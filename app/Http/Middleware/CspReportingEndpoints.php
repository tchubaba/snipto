<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emits the Reporting-Endpoints header that maps the CSP `report-to` group
 * (config('csp.report_to')) to the concrete collector URL.
 *
 * Chrome 118+ removed the legacy Report-To header; `report-to <group>` in the
 * CSP header is inert unless a Reporting-Endpoints header resolves the group
 * to an endpoint. spatie/laravel-csp emits the CSP directive from
 * config('csp.report_to') but not this mapping, so we add it here. Reports
 * land on CspReportController (/api/csp-report).
 */
class CspReportingEndpoints
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $group = config('csp.report_to');

        if (config('csp.enabled') && is_string($group) && $group !== '') {
            $endpoint = rtrim((string) config('app.url'), '/')
                . (string) config('csp.report_uri', '/api/csp-report');

            $response->headers->set('Reporting-Endpoints', "{$group}=\"{$endpoint}\"");
        }

        return $response;
    }
}
