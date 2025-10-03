<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Log;

class CspReportController extends Controller
{
    /**
     * Handles a Content Security Policy (CSP) violation report.
     *
     * This method validates the incoming request to ensure it contains
     * a valid CSP report payload. The payload is then checked against
     * defined validation rules. If any validation errors occur, an error
     * response is returned. Valid CSP violations are logged using a
     * specific log channel.
     *
     * @param Request $request The HTTP request instance containing the CSP report payload.
     *
     * @return Response|JsonResponse Returns a no-content response for successful processing
     *                                or a JSON response containing error details if validation fails.
     */
    public function report(Request $request): Response|JsonResponse
    {
        // Validate content type
        if ($request->header('Content-Type') !== 'application/csp-report') {
            return response()->json(['error' => 'Invalid content type'], 400);
        }

        $payload = $request->json()->all();

        // Define validation rules
        $validator = Validator::make($payload, [
            'csp-report' => ['required', 'array'], // top-level object

            // Nested keys
            'csp-report.blocked-uri'         => ['required', 'string'],
            'csp-report.column-number'       => ['nullable', 'integer', 'min:0'],
            'csp-report.disposition'         => ['required', 'string', 'in:enforce,report'],
            'csp-report.document-uri'        => ['required', 'url', 'starts_with:' . config('app.url')],
            'csp-report.effective-directive' => ['required', 'string'],
            'csp-report.line-number'         => ['nullable', 'integer', 'min:0'],
            'csp-report.original-policy'     => ['required', 'string'],
            'csp-report.referrer'            => ['nullable', 'string'],
            'csp-report.source-file'         => ['nullable', 'string'],
            'csp-report.status-code'         => ['required', 'integer', 'min:100', 'max:599'],
            'csp-report.violated-directive'  => ['required', 'string'],
        ]);

        // Check for validation errors
        if ($validator->fails()) {
            Log::channel('csp')->error('Invalid CSP report', $validator->errors()->all());
            return response()->json(['error' => 'Invalid CSP report'], 400);
        }

        // Log valid report
        Log::channel('csp')->warning('CSP Violation', $payload['csp-report']);
        return response()->noContent();
    }
}
