<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Log;

class CspReportController extends Controller
{
    /**
     * Handles a Content Security Policy (CSP) violation report.
     *
     * Accepts both report formats browsers may send:
     *   - Legacy `report-uri` directive: Content-Type `application/csp-report`,
     *     body `{"csp-report": {...kebab-case fields...}}`.
     *   - Modern Reporting API (`report-to` directive): Content-Type
     *     `application/reports+json`, body `[{"type": "csp-violation",
     *     "body": {...camelCase fields...}}, ...]` (an array of reports).
     *
     * Both shapes are normalized to a single canonical kebab-case form before
     * validation and logging. Field validation is intentionally permissive:
     * we want to log partial or unusual reports rather than reject them, since
     * CSP reports are noisy by nature (browser extensions, edge proxies,
     * inline anti-bot scripts) and a missing field is no reason to drop the
     * signal.
     *
     * @param  Request  $request  The HTTP request instance containing the CSP report payload.
     * @return Response|JsonResponse 204 on success, 400 when the body shape is unrecognizable.
     */
    public function report(Request $request): Response|JsonResponse
    {
        $contentType = $request->header('Content-Type', '');
        $reports     = [];

        if (str_contains($contentType, 'application/csp-report')) {
            $payload = $request->json()->all();
            if (isset($payload['csp-report']) && is_array($payload['csp-report'])) {
                $reports[] = $payload['csp-report'];
            }
        } elseif (str_contains($contentType, 'application/reports+json')) {
            $payload = $request->json()->all();
            foreach ((is_array($payload) ? $payload : []) as $entry) {
                if ( ! is_array($entry) || ($entry['type'] ?? '') !== 'csp-violation') {
                    continue;
                }
                if (isset($entry['body']) && is_array($entry['body'])) {
                    $reports[] = $this->normalizeReportingApi($entry['body']);
                }
            }
        } else {
            return response()->json(['error' => 'Invalid content type'], 400);
        }

        if ($reports === []) {
            return response()->json(['error' => 'Invalid CSP report'], 400);
        }

        foreach ($reports as $report) {
            $validator = Validator::make($report, [
                'blocked-uri'         => ['nullable', 'string'],
                'document-uri'        => ['nullable', 'string'],
                'effective-directive' => ['nullable', 'string'],
                'violated-directive'  => ['nullable', 'string'],
                'original-policy'     => ['nullable', 'string'],
                'source-file'         => ['nullable', 'string'],
                'line-number'         => ['nullable', 'integer', 'min:0'],
                'column-number'       => ['nullable', 'integer', 'min:0'],
                'status-code'         => ['nullable', 'integer', 'between:0,599'],
                'disposition'         => ['nullable', 'string', 'in:enforce,report'],
                'referrer'            => ['nullable', 'string'],
                'sample'              => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                Log::channel('csp')->error('Invalid CSP report fields', [
                    'errors' => $validator->errors()->all(),
                    'report' => $report,
                ]);

                continue;
            }

            Log::channel('csp')->warning('CSP Violation', $report);
        }

        return response()->noContent();
    }

    /**
     * Map Reporting-API camelCase fields onto our canonical kebab-case shape so
     * both legacy and modern reports validate and log identically.
     */
    private function normalizeReportingApi(array $body): array
    {
        return [
            'blocked-uri'         => $body['blockedURL'] ?? null,
            'document-uri'        => $body['documentURL'] ?? null,
            'effective-directive' => $body['effectiveDirective'] ?? null,
            'violated-directive'  => $body['effectiveDirective'] ?? null,
            'original-policy'     => $body['originalPolicy'] ?? null,
            'source-file'         => $body['sourceFile'] ?? null,
            'line-number'         => $body['lineNumber'] ?? null,
            'column-number'       => $body['columnNumber'] ?? null,
            'status-code'         => $body['statusCode'] ?? null,
            'disposition'         => $body['disposition'] ?? null,
            'referrer'            => $body['referrer'] ?? null,
            'sample'              => $body['sample'] ?? null,
        ];
    }
}
