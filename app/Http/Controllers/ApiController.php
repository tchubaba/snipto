<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProtectionType;
use App\Models\Snipto;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Log;
use Throwable;

class ApiController extends Controller
{
    /**
     * Displays the specified Snipto resource by its unique slug.
     *
     * Behavior:
     * - If the slug does not match an existing record, returns a 404.
     * - If the record exists but has expired, deletes it and returns a 404 (the slug is
     *   effectively free to reuse; treating expired-as-not-found also avoids leaking the
     *   prior existence of a snipto to slug probers).
     * - For Plaintext sniptos, returns the payload immediately and decrements views.
     * - For encrypted sniptos without a valid key_hash, returns metadata only (protection
     *   type plus Snipto ID / Password fields the client needs to derive its key_hash).
     * - For encrypted sniptos with a valid key_hash, returns the payload, nonce, and
     *   decremented views_remaining. An invalid key_hash returns 403.
     *
     * @param  string  $slug  The unique identifier for retrieving the Snipto resource.
     * @return JsonResponse A structured JSON response containing the requested data or an error message.
     */
    public function show(string $slug, Request $request): JsonResponse
    {
        $snipto = Snipto::where('slug', $slug)->first();

        if ( ! $snipto || $snipto->isExpired()) {
            $snipto?->delete();

            return response()->json([
                'success' => false,
                'exists'  => false,
                'message' => 'Snipto not found',
            ], 404);
        }

        if ( ! $snipto->isEncrypted()) {
            return response()->json([
                'success'         => true,
                'exists'          => true,
                'protection_type' => $snipto->protection_type->value,
                'payload'         => $snipto->payload,
                'views_remaining' => $snipto->decrementViews(),
            ]);
        }

        $keyHash = $request->string('key_hash')->toString();

        if (empty($keyHash)) {
            $response = [
                'success'         => true,
                'exists'          => true,
                'protection_type' => $snipto->protection_type->value,
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
            if ($snipto->isPasswordProtected()) {
                $response['nonce'] = $snipto->nonce;
            }

            return response()->json($response);
        }

        if ( ! hash_equals($snipto->key_hash, $keyHash)) {
            return response()->json([
                'success' => false,
                'message' => 'Key Hash invalid.',
            ], 403);
        }

        return response()->json([
            'success'         => true,
            'exists'          => true,
            'protection_type' => $snipto->protection_type->value,
            'payload'         => $snipto->payload,
            'nonce'           => $snipto->nonce,
            'views_remaining' => $snipto->decrementViews(),
        ]);
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
            'slug'            => 'required|string|max:100|unique:sniptos,slug',
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
