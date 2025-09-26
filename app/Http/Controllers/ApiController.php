<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Snipto;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
                'message' => 'Snipto expired',
            ], 410);
        }

        if ( ! empty($keyHash) && $snipto->key_hash !== $keyHash) {
            return response()->json([
                'success' => false,
                'message' => 'Key Hash invalid.',
            ], 403);
        }

        $response = [
            'success'         => true,
            'exists'          => true,
            'views_remaining' => $snipto->views_remaining,
            'expires_at'      => $snipto->expires_at,
        ];

        if ( ! empty($keyHash)) {
            $response['payload']        = $snipto->payload;
            $response['plaintext_hmac'] = $snipto->plaintext_hmac;
            $response['nonce']          = $snipto->nonce;
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
        $validator = Validator::make($request->all(), [
            'slug'            => 'required|string|max:100|unique:sniptos,slug',
            'payload'         => 'required|string|min:1',
            'key_hash'        => 'required|string|size:64|regex:/^[0-9a-fA-F]+$/',
            'plaintext_hmac'  => 'required|string|size:64|regex:/^[0-9a-fA-F]+$/',
            'nonce'           => 'required|string|size:24|regex:/^[0-9a-fA-F]+$/',
            'views_remaining' => 'nullable|integer|min:1|max:200',
            'expires_at'      => 'nullable|date|after:now', // TODO: perhaps use pre-defined values like 1 day, 1 week, etc
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Forcing expiration date to always be 1 week from now.
        $validated = $validator->validated();
        //        if (empty($validated['expires_at'])) {
        $validated['expires_at'] = Carbon::now()->addWeek();
        //        }

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

    /**
     * Marks a Snipto resource as viewed and validates the provided payload hash.
     *
     * This method ensures the integrity of the request by validating the hash of the
     * payload against the stored Snipto payload hash. If the `payload_hash` matches and
     * the Snipto exists, the view count is decremented. Returns appropriate JSON
     * responses for success or failure scenarios.
     *
     * Validation rules:
     * - 'payload_hash': Required, string, exactly 64 characters, must be a valid SHA-256 hash.
     *
     * Workflow:
     * - Retrieves the Snipto record by the provided `slug`.
     * - Confirms the existence of the Snipto.
     * - Validates the hash of the payload against the input.
     * - Decrements the views remaining if validation is successful.
     *
     * @param string $slug The unique identifier of the Snipto resource.
     * @param Request $request The incoming HTTP request containing 'payload_hash'.
     *
     * @return JsonResponse A JSON response indicating the success or failure of the operation.
     */
    public function markViewed(string $slug, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plaintext_hmac' => 'required|string|size:64|regex:/^[0-9a-fA-F]+$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $snipto = Snipto::where('slug', $slug)->first();

        if ( ! $snipto) {
            return response()->json([
                'success' => false,
                'message' => 'Snipto not found',
            ], 404);
        }

        if ($snipto->plaintext_hmac
            !== $request->input('plaintext_hmac')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payload hash',
            ], 400);
        }

        $snipto->decrementViews();

        return response()->json([
            'success'         => true,
            'message'         => 'Marked as viewed',
            'views_remaining' => $snipto->views_remaining,
        ]);
    }
}
