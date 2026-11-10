<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProtectionType;
use App\Models\Snipto;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

            // A file-mode row whose blob has not landed yet is invisible to viewers —
            // same expired-as-absent philosophy. But do NOT delete merely because the
            // upload is incomplete: the creator's 50 MiB PUT may still be in flight.
            // Abandoned uploads are reaped by sniptos:purge instead.
            if ($snipto->isUploadIncomplete()) {
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
                'kind'            => $snipto->kind,
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
                // File-mode reveals answer with a bare binary body that carries
                // no JSON envelope, so the mode-3 client must learn the nonce
                // here instead. It is an IV, public by the same reasoning as
                // the salt and sender pubkey above.
                $response['nonce']             = $snipto->nonce;
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
     * @return Response|JsonResponse The payload on success — JSON for text mode, raw
     *                               octet-stream for file mode; 403 for an
     *                               invalid/expired/consumed token or key_hash; 404
     *                               when the snipto does not exist.
     */
    public function reveal(string $slug, Request $request): Response|JsonResponse
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

            // Incomplete file uploads are invisible here too (see show()): 404,
            // and NO delete — the creator's PUT may still be in flight.
            if ($snipto->isUploadIncomplete()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Snipto not found',
                ], 404);
            }
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
                    $payload = $snipto->payload;
                    $blob    = $snipto->payload_blob;
                    $isFile  = $snipto->isFile();
                    $snipto->delete();

                    if ($isFile) {
                        // File mode: the body IS the opaque ciphertext — served raw,
                        // never inspected, never base64'd. Errors stay JSON; the
                        // client branches on Content-Type.
                        return response((string) $blob, 200, [
                            'Content-Type'        => 'application/octet-stream',
                            'Content-Disposition' => 'attachment; filename="snipto"',
                            'X-Accel-Buffering'   => 'no',
                            'Cache-Control'       => 'no-store',
                        ]);
                    }

                    return response()->json([
                        'success'         => true,
                        'protection_type' => $snipto->protection_type->value,
                        'payload'         => $payload,
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

                if ($snipto->isFile()) {
                    return response((string) $snipto->payload_blob, 200, [
                        'Content-Type'        => 'application/octet-stream',
                        'Content-Disposition' => 'attachment; filename="snipto"',
                        'X-Accel-Buffering'   => 'no',
                        'Cache-Control'       => 'no-store',
                    ]);
                }

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

        $isFile = $request->input('kind') === 'file';

        // File mode is encrypted-only: for Plaintext the slug is the ONLY secret,
        // and pointing it at a large high-value target is a downgrade we refuse.
        if ($isFile && $protectionType === ProtectionType::Plaintext) {
            return response()->json(['success' => false], 422);
        }

        $rules = [
            // The charset rule must stay in lockstep with the slug route regex in
            // routes/web.php ([A-Za-z0-9_-]+): a slug that passes validation but fails
            // the route regex is unreachable, undeletable, and lazily-expire-proof —
            // a permanent orphan (pentest finding F-003). The length floor closes F-005:
            // for Plaintext sniptos the slug IS the only secret, so it needs entropy;
            // for encrypted modes the slug is just an identifier. Reserved slugs are
            // shadowed by static routes and would be unreachable from birth.
            'slug' => [
                // 'unique:sniptos,slug' is added below for text mode only. File
                // mode handles existence itself: an INCOMPLETE file row (store
                // succeeded, PUT never landed — creator hit a network drop or a
                // size cliff) must be replaceable by a re-store of the same
                // slug, otherwise the creator is dead-ended: the row is
                // invisible (404), there is no DELETE endpoint, and the slug
                // stays locked behind 422 until purge. Completed rows and text
                // rows keep the hard 422 — nobody can clobber a landed payload.
                'required', 'string', 'max:100',
                'regex:/^[A-Za-z0-9_-]+$/',
                $protectionType === ProtectionType::Plaintext ? 'min:8' : 'min:4',
                'not_in:' . implode(',', self::RESERVED_SLUGS),
            ],
            'kind'            => ['nullable', 'in:text,file'],
            'protection_type' => ['required', new Enum(ProtectionType::class)],
            'views_remaining' => 'nullable|integer|min:1|max:200',
            'expiration'      => 'nullable|string|in:1h,1d,1w',
        ];

        if ( ! $isFile) {
            $rules['slug'][] = 'unique:sniptos,slug';
        }

        if ($protectionType && $protectionType !== ProtectionType::Plaintext) {
            // Binary transport: the payload arrives later via
            // PUT /api/snipto/{slug}/payload and is validated there by body size.
            $rules['payload'] = $isFile ? 'nullable|string|max:0' : [
                'required',
                'string',
                'min:4',
                'max:10485760', // 10 MB (10 * 1024 * 1024 bytes)
                'regex:/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/',
            ];
            $rules['key_hash'] = 'required|string|size:64|regex:/^[0-9a-fA-F]+$/';
            $rules['nonce']    = 'required|string|size:24|regex:/^[0-9a-fA-F]+$/';

            if ($isFile) {
                $rules['upload_token'] = 'nullable|string|size:64|regex:/^[0-9a-fA-F]+$/';
            }

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
        $priorUploadToken        = (string) ($validated['upload_token'] ?? $request->header('X-Upload-Token', ''));
        unset($validated['upload_token']);
        $validated['expires_at'] = $expiresAt;
        $validated['kind']       = $isFile ? 'file' : 'text';

        // Forcing all sniptos to have only one view for now.
        $validated['views_remaining'] = 1;

        if ($isFile) {
            // One-time upload token, same hardening lineage as reveal_token
            // (pentest F-001): only its SHA-256 is stored, it travels in a header
            // (never a URL), and it is consumed once the creator's upload attempt
            // authenticates.
            $uploadToken                          = bin2hex(random_bytes(32));
            $validated['payload']                 = null;
            $validated['upload_token_hash']       = hash('sha256', $uploadToken);
            $validated['upload_token_expires_at'] = now()->addMinutes(30);
        }

        try {
            $snipto = DB::transaction(function () use ($isFile, $validated, $priorUploadToken): ?Snipto {
                if ($isFile) {
                    // Reset-or-create inside an explicit transaction so lockForUpdate()
                    // holds the InnoDB row lock across the check and write.
                    // If the incomplete row still has a live, unburned upload token
                    // (creator's PUT is in flight or dropped before reaching PHP),
                    // replacing it requires presenting that token so a third party
                    // cannot hijack an in-flight upload. Once a PUT has burned or
                    // expired the token without landing a blob, the row can be reset
                    // directly. Completed rows and text rows stay hard 422.
                    $existing = Snipto::where('slug', $validated['slug'])->lockForUpdate()->first();

                    if ($existing) {
                        if ($existing->isExpired()) {
                            $existing->delete();
                        } elseif ($existing->isFile() && $existing->isUploadIncomplete()) {
                            $hasActiveToken = $existing->upload_token_hash !== null
                                && $existing->upload_token_expires_at !== null
                                && $existing->upload_token_expires_at->isFuture();

                            $tokenMatches = $priorUploadToken !== ''
                                && $existing->upload_token_hash !== null
                                && hash_equals($existing->upload_token_hash, hash('sha256', $priorUploadToken));

                            if ($hasActiveToken && ! $tokenMatches) {
                                return null;
                            }

                            $existing->fill($validated);
                            $existing->payload_blob            = null;
                            $existing->reveal_token_hash       = null;
                            $existing->reveal_token_expires_at = null;
                            $existing->created_at              = now();
                            $existing->save();

                            return $existing;
                        } else {
                            return null;
                        }
                    }
                }

                return Snipto::create($validated);
            });

            if ($snipto === null) {
                return response()->json(['success' => false], 422);
            }
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

        $response = [
            'success' => true,
            'slug'    => $snipto->slug,
        ];

        // The key is added only for file mode so the existing text-mode API
        // contract stays byte-identical.
        if ($isFile) {
            $response['upload_token'] = $uploadToken;
        }

        return response()->json($response);
    }

    /**
     * Accepts the encrypted binary payload for a file-mode Snipto. The ONLY
     * endpoint that writes payload_blob. The row created by store() is invisible
     * to viewers until this lands (see show()/reveal()), and a completed upload
     * can never be overwritten. The body is raw ciphertext bytes — opaque,
     * never inspected, never logged.
     */
    public function upload(string $slug, Request $request): JsonResponse
    {
        return DB::transaction(function () use ($slug, $request) {
            $snipto = Snipto::where('slug', $slug)->lockForUpdate()->first();

            if ( ! $snipto || $snipto->isExpired()) {
                $snipto?->delete();

                return response()->json(['success' => false, 'message' => 'Snipto not found'], 404);
            }

            // A text-mode snipto is never an upload target — answer 404 without
            // deleting the live text row.
            if ( ! $snipto->isFile()) {
                return response()->json(['success' => false, 'message' => 'Snipto not found'], 404);
            }

            $token   = (string) $request->header('X-Upload-Token', '');
            $tokenOk = $token !== ''
                && $snipto->upload_token_hash !== null
                && $snipto->upload_token_expires_at !== null
                && $snipto->upload_token_expires_at->isFuture()
                && hash_equals($snipto->upload_token_hash, hash('sha256', $token));

            // Verify the 256-bit upload token BEFORE burning it so an
            // unauthenticated probe cannot sabotage an in-flight upload.
            if ( ! $tokenOk || $snipto->payload_blob !== null) {
                return response()->json(['success' => false, 'message' => 'Upload token invalid or expired.'], 403);
            }

            // Authenticated attempt consumes the one-time upload token.
            $this->burnUploadToken($snipto);

            $maxBody = (int) config('snipto.max_file_bytes')
                + (int) config('snipto.envelope_slack_bytes');

            // Fast path: a lying/absent Content-Length is caught by the capped read.
            if ((int) $request->header('Content-Length', '-1') > $maxBody) {
                return response()->json(['success' => false, 'message' => 'Payload too large.'], 413);
            }

            $body = $this->readBodyCapped($request, $maxBody);
            if ($body === null || $body === '') {
                return response()->json(['success' => false, 'message' => 'Payload too large or empty.'], 413);
            }

            $snipto->payload_blob = $body;
            $snipto->save();

            return response()->json(['success' => true, 'slug' => $snipto->slug]);
        });
    }

    private function burnUploadToken(Snipto $snipto): void
    {
        $snipto->upload_token_hash       = null;
        $snipto->upload_token_expires_at = null;
        $snipto->save();
    }

    /**
     * Reads the request body as a stream so a client that lies about
     * Content-Length still cannot make us allocate beyond the cap.
     */
    private function readBodyCapped(Request $request, int $cap): ?string
    {
        $fh   = $request->getContent(true); // resource handle on php://input
        $body = '';

        try {
            while ( ! feof($fh)) {
                $chunk = fread($fh, 65536);
                if ($chunk === false) {
                    return null;
                }
                $body .= $chunk;
                if (strlen($body) > $cap) {
                    return null;
                }
            }
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }

        return $body;
    }
}
