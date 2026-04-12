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
     * This method retrieves a Snipto record based on the provided slug. If no such record
     * exists, it returns a 404 error response. If the record exists but is expired,
     * the record is deleted and a 410 error response is returned. Otherwise, the method
     * responds with the Snipto's details as a JSON object.
     *
     * Behavior:
     * - If the slug does not match an existing record, returns a 404 response indicating the resource was not found.
     * - If the record exists but has expired, deletes the record and returns a 410 response indicating the expiration.
     * - If the record exists and is not expired, returns its details including payload, IV, remaining views, and expiration timestamp.
     *
     * @param string $slug The unique identifier for retrieving the Snipto resource.
     *
     * @return JsonResponse A structured JSON response containing the requested data or an error message.
     */
    public function show(string $slug, Request $request): JsonResponse
    {
        $keyHash = $request->input('key_hash');

        $snipto = Snipto::where('slug', $slug)->first();

        if ( ! $snipto) {
            return response()->json([
                'success' => false,
                'exists'  => false,
                'message' => 'Snipto not found',
            ], 404);
        }

        if ($snipto->isExpired()) {
            $snipto->delete();
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

        if ( ! empty($keyHash) && $snipto->key_hash !== $keyHash) {
            return response()->json([
                'success' => false,
                'message' => 'Key Hash invalid.',
            ], 403);
        }

        // The base response for when a snipto is found.
        $response = [
            'success'         => true,
            'exists'          => true,
            'protection_type' => $snipto->protection_type->value,
        ];

        // If the key hash is present and valid, add the payload, decrement views and include view_remaining.
        if ( ! empty($keyHash)) {
            $response['payload']         = $snipto->payload;
            $response['nonce']           = $snipto->nonce;
            $response['views_remaining'] = $snipto->decrementViews();
        }

        return response()->json($response);
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
     * @param Request $request The incoming HTTP request containing the data to store.
     *
     * @return JsonResponse A JSON response containing the success status.
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $protectionType = ProtectionType::tryFrom((int)$request->input('protection_type'));

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

        if ($protectionType === ProtectionType::Password) {
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
