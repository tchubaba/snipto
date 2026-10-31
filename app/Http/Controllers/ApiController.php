<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProtectionType;
use App\Models\Snipto;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Log;
use Throwable;

class ApiController extends Controller
{
    /**
     * Slugs shadowed by static or framework routes (routes/web.php, the /up health
     * check, and the /api prefix itself). A snipto created with one of these can never
     * be fetched, so creation is rejected outright rather than storing an orphan.
     * Exact lowercase match: route matching is case-sensitive, so 'FAQ' stays reachable.
     */
    public const RESERVED_SLUGS = ['faq', 'contact', 'terms', 'safety', 'sniptoid', 'locale', 'api', 'up'];

    /**
     * Probes the specified Snipto resource by its unique slug — metadata only.
     *
     * This endpoint is side-effect free with respect to the snipto lifecycle: it NEVER
     * returns the payload and NEVER decrements views, so link prefetchers, URL
     * reputation scanners, and crawlers can no longer burn a snipto by GETting it
     * (pentest finding F-002). Every successful probe mints a fresh one-time reveal
     * token; the payload is only ever released by reveal(), which takes the token and
     * the key_hash in a POST body — never in a URL query string, where production
     * access logs would capture the retrieval credential (pentest finding F-001).
     *
     * Behavior:
     * - If the slug does not match an existing record, returns a 404.
     * - If the record exists but has expired, deletes it and returns a 404 (the slug is
     *   effectively free to reuse; treating expired-as-not-found also avoids leaking the
     *   prior existence of a snipto to slug probers).
     * - Otherwise returns the protection type, a fresh reveal_token, and the public
     *   key-derivation bootstrap fields for the protection mode (nonce for Password /
     *   URL Secret; sender_public_key, key_provider_type, recipient_salt for Snipto ID).
     *
     * @param  string  $slug  The unique identifier for retrieving the Snipto resource.
     * @return JsonResponse A structured JSON response containing the metadata or an error message.
     */
    public function show(string $slug): JsonResponse
    {
        return DB::transaction(function () use ($slug) {
            $snipto = Snipto::where('slug', $slug)->lockForUpdate()->first();

            if ( ! $snipto || $snipto->isExpired()) {
                $snipto?->delete();

                return response()->json([
                    'success' => false,
                    'exists'  => false,
                    'message' => 'Snipto not found',
                ], 404);
            }

            // Mint a fresh one-time reveal token. Only its SHA-256 is stored; the raw
            // token travels in the response body. A new GET overwrites the previous
            // token, so only the most recent probe can ever lead to a reveal.
            $token                     = bin2hex(random_bytes(32));
            $snipto->reveal_token_hash = hash('sha256', $token);
            // 15-minute TTL, never outliving the snipto itself.
            $snipto->reveal_token_expires_at = now()->addMinutes(15)->min($snipto->expires_at);
            $snipto->save();

            $response = [
                'success'         => true,
                'exists'          => true,
                'protection_type' => $snipto->getProtectionType()->value,
                'reveal_token'    => $token,
            ];

            // Snipto ID mode derives key_hash from the ECDH shared secret between the
            // sender's ephemeral pubkey and the recipient's private key. The recipient
            // re-derives that private key from passphrase + recipient_salt, then completes
            // ECDH against sender_public_key, before it can present a valid key_hash. All
            // three fields are public by design (the salt and pubkey ship inside the
            // recipient's published Snipto ID); key_provider_type just tells the client
            // which derivation to run.
            if ($snipto->isSniptoId()) {
                $response['sender_public_key'] = $snipto->sender_public_key;
                $response['key_provider_type'] = $snipto->key_provider_type;
                $response['recipient_salt']    = $snipto->recipient_salt;
            }

            // Password mode derives key_hash from Argon2id(password, ...nonce-derived salt...).
            // The recipient needs the nonce to reproduce the derivation before it can present
            // a valid key_hash, so it is exposed pre-auth. The nonce is an IV/salt, not a secret.
            // URL Secret (Mode 1) has the same chicken-and-egg problem. The nonce is an IV/salt,
            // not a secret.
            if ($snipto->isPasswordProtected() || $snipto->isUrlSecret()) {
                $response['nonce'] = $snipto->nonce;
            }

            return response()->json($response);
        });
    }

    /**
     * Releases the payload of the specified Snipto resource. The ONLY endpoint that
     * returns payload data or decrements views (see show() for the F-001/F-002 rationale).
     *
     * The request body carries:
     * - 'reveal_token': the one-time token issued by the most recent metadata GET.
     * - 'key_hash': required for encrypted sniptos; presented in the POST body so the
     *   retrieval credential never appears in a URL, access log, or Referer header.
     *
     * The reveal token is consumed on ANY attempt — success or failure — so a failed
     * key_hash probe cannot be retried against the same token; the client must re-GET,
     * which re-enters the show-snipto throttle tiers.
     *
     * @param  string  $slug  The unique identifier for retrieving the Snipto resource.
     * @return JsonResponse The payload on success; 403 for an invalid/expired/consumed
     *                      token or key_hash; 404 when the snipto does not exist.
     */
    public function reveal(string $slug, Request $request): JsonResponse
    {
        return DB::transaction(function () use ($slug, $request) {
            $snipto = Snipto::where('slug', $slug)->lockForUpdate()->first();

            if ( ! $snipto || $snipto->isExpired()) {
                $snipto?->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Snipto not found',
                ], 404);
            }

            $token   = $request->string('reveal_token')->toString();
            $tokenOk = $snipto->reveal_token_hash !== null
                && $snipto->reveal_token_expires_at !== null
                && $snipto->reveal_token_expires_at->isFuture()
                && $token !== ''
                && hash_equals($snipto->reveal_token_hash, hash('sha256', $token));

            // key_hash is only ever compared when the token itself checked out —
            // same ordering as before, so a bad token never yields a key_hash oracle.
            $keyHashOk = true;
            if ($tokenOk && $snipto->isEncrypted()) {
                $keyHash   = $request->string('key_hash')->toString();
                $keyHashOk = $keyHash !== '' && hash_equals((string) $snipto->key_hash, $keyHash);
            }

            // A reveal attempt always consumes its token, right or wrong — but the
            // consumption is now paid for in at most ONE write. Every row the
            // current API can create is single-view (store() forces 1 and migration
            // 2026_10_20_190200 made the column NOT NULL), so a successful reveal
            // deletes the row outright: the old token-nulling UPDATE and the
            // view-decrement UPDATE only churned a row this same transaction then
            // removed. A null views_remaining (unreachable since the tightening
            // migration) is treated fail-closed — delete, never "unlimited".
            if ($tokenOk && $keyHashOk) {
                if ((int) $snipto->views_remaining <= 1) {
                    $snipto->delete();

                    return response()->json([
                        'success'         => true,
                        'protection_type' => $snipto->protection_type->value,
                        'payload'         => $snipto->payload,
                        'nonce'           => $snipto->nonce,
                        'views_remaining' => 0,
                    ]);
                }

                // Multi-view stragglers: null the token and decrement the counter
                // in a single UPDATE. The FOR UPDATE lock makes the in-memory
                // arithmetic safe for the duration of this transaction.
                $snipto->views_remaining -= 1;
                $snipto->reveal_token_hash       = null;
                $snipto->reveal_token_expires_at = null;
                $snipto->save();

                return response()->json([
                    'success'         => true,
                    'protection_type' => $snipto->protection_type->value,
                    'payload'         => $snipto->payload,
                    'nonce'           => $snipto->nonce,
                    'views_remaining' => $snipto->views_remaining,
                ]);
            }

            // Failed attempt: the row survives, so burn the token with exactly one
            // UPDATE (save() issues no query when nothing was dirty — e.g. an
            // already-consumed token whose fields are already null).
            $snipto->reveal_token_hash       = null;
            $snipto->reveal_token_expires_at = null;
            $snipto->save();

            return response()->json([
                'success' => false,
                'message' => $tokenOk ? 'Key Hash invalid.' : 'Reveal token invalid or expired.',
            ], 403);
        });
    }

    /**
     * Handles the storage of a new Snipto resource.
     *
     * This method validates the incoming request data, ensuring all required fields meet the
     * specified criteria. Once validated, the method creates a new Snipto record using
     * the provided data and returns a JSON response indicating successful creation.
     *
     * Validation rules:
     * - 'slug': Required, string, maximum of 100 characters, must be unique in the 'sniptos' table.
     * - 'payload': Required, string.
     * - 'iv': Required, string.
     * - 'views_remaining': Optional, integer, minimum value of 1.
     * - 'expires_at': Required, date, must be a future date.
     *
     * @param  Request  $request  The incoming HTTP request containing the data to store.
     * @return JsonResponse A JSON response containing the success status.
     *
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $protectionType = ProtectionType::tryFrom((int) $request->input('protection_type'));

        $rules = [
            // The charset rule must stay in lockstep with the slug route regex in
            // routes/web.php ([A-Za-z0-9_-]+): a slug that passes validation but fails
            // the route regex is unreachable, undeletable, and lazily-expire-proof —
            // a permanent orphan (pentest finding F-003). The length floor closes F-005:
            // for Plaintext sniptos the slug IS the only secret, so it needs entropy;
            // for encrypted modes the slug is just an identifier. Reserved slugs are
            // shadowed by static routes and would be unreachable from birth.
            'slug' => [
                'required', 'string', 'max:100', 'unique:sniptos,slug',
                'regex:/^[A-Za-z0-9_-]+$/',
                $protectionType === ProtectionType::Plaintext ? 'min:8' : 'min:4',
                'not_in:' . implode(',', self::RESERVED_SLUGS),
            ],
            'protection_type' => ['required', new Enum(ProtectionType::class)],
            'views_remaining' => 'nullable|integer|min:1|max:200',
            'expiration'      => 'nullable|string|in:1h,1d,1w',
        ];

        if ($protectionType && $protectionType !== ProtectionType::Plaintext) {
            $rules['payload'] = [
                'required',
                'string',
                'min:4',
                'max:10485760', // 10 MB (10 * 1024 * 1024 bytes)
                'regex:/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/',
            ];
            $rules['key_hash'] = 'required|string|size:64|regex:/^[0-9a-fA-F]+$/';
            $rules['nonce']    = 'required|string|size:24|regex:/^[0-9a-fA-F]+$/';

            if ($protectionType === ProtectionType::SniptoId) {
                $rules['sender_public_key'] = ['required', 'string', 'size:44', 'regex:/^[A-Za-z0-9+\/]{43}=$/'];
                $rules['key_provider_type'] = 'nullable|string|in:passphrase';
                $rules['recipient_salt']    = ['required', 'string', 'size:24', 'regex:/^[A-Za-z0-9+\/]{22}==$/'];
            }
        } else {
            $rules['payload'] = [
                'required',
                'string',
                'min:1',
                'max:10485760', // 10 MB
            ];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                //                'errors'  => $validator->errors(), // Not an API for public consumption, so no need to include errors.
            ], 422);
        }

        // Logic for expiration
        $expiration = $request->input('expiration', '1h');
        $expiresAt  = Carbon::now()->addHour();

        if ($protectionType === ProtectionType::Password || $protectionType === ProtectionType::SniptoId) {
            $expiresAt = match ($expiration) {
                '1d'    => Carbon::now()->addDay(),
                '1w'    => Carbon::now()->addWeek(),
                default => Carbon::now()->addHour(),
            };
        }

        $validated               = $validator->validated();
        $validated['expires_at'] = $expiresAt;

        // Forcing all sniptos to have only one view for now.
        $validated['views_remaining'] = 1;

        try {
            $snipto = Snipto::create($validated);
        } catch (QueryException $t) {
            // Lost the unique-slug race between validation and insert (pentest AUTHZ-008):
            // SQLSTATE 23000 covers MariaDB's 1062 and SQLite's "UNIQUE constraint failed".
            // This is a client-collidable condition, not a server fault — answer 409 and
            // keep it out of the error log.
            if ($t->getCode() === '23000') {
                return response()->json([
                    'success' => false,
                    'message' => 'Slug already taken.',
                ], 409);
            }

            Log::error(sprintf(
                'Error saving a snipto: %s',
                $t->getMessage()
            ));

            return response()->json([
                'success' => false,
                'message' => 'An internal error occurred and the snipto could not be saved.',
            ], 500);
        } catch (Throwable $t) {
            Log::error(sprintf(
                'Error saving a snipto: %s',
                $t->getMessage()
            ));

            return response()->json([
                'success' => false,
                'message' => 'An internal error occurred and the snipto could not be saved.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'slug'    => $snipto->slug,
        ]);
    }
}
