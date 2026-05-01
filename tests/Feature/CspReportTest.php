<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CspReportTest extends TestCase
{
    #[Test]
    public function it_accepts_a_legacy_csp_report(): void
    {
        $report = [
            'csp-report' => [
                'document-uri'        => 'https://snipto.net/test',
                'referrer'            => '',
                'violated-directive'  => 'script-src-elem',
                'effective-directive' => 'script-src-elem',
                'original-policy'     => "default-src 'none'; script-src 'self'",
                'disposition'         => 'enforce',
                'blocked-uri'         => 'inline',
                'line-number'         => 1,
                'column-number'       => 1,
                'source-file'         => 'https://snipto.net/test',
                'status-code'         => 200,
            ],
        ];

        $response = $this->call(
            'POST',
            '/api/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/csp-report'],
            json_encode($report),
        );

        $response->assertNoContent();
    }

    #[Test]
    public function it_accepts_a_partial_legacy_report_with_only_blocked_and_directive(): void
    {
        // Trusted Types violations from older browsers may omit original-policy
        // and other fields. We log what we get; we don't reject.
        $report = [
            'csp-report' => [
                'document-uri'        => 'https://snipto.net/test',
                'effective-directive' => 'require-trusted-types-for',
                'violated-directive'  => 'require-trusted-types-for',
                'blocked-uri'         => 'trusted-types-sink',
            ],
        ];

        $response = $this->call(
            'POST',
            '/api/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/csp-report'],
            json_encode($report),
        );

        $response->assertNoContent();
    }

    #[Test]
    public function it_accepts_a_reporting_api_payload(): void
    {
        // Reporting API: array of reports with camelCase fields under `body`.
        $payload = [
            [
                'type' => 'csp-violation',
                'age'  => 0,
                'url'  => 'https://snipto.net/test',
                'body' => [
                    'documentURL'        => 'https://snipto.net/test',
                    'blockedURL'         => 'inline',
                    'effectiveDirective' => 'script-src-elem',
                    'originalPolicy'     => "default-src 'none'; script-src 'self'",
                    'disposition'        => 'enforce',
                    'lineNumber'         => 582,
                    'columnNumber'       => 206,
                    'sourceFile'         => 'https://snipto.net/test',
                    'statusCode'         => 200,
                ],
            ],
        ];

        $response = $this->call(
            'POST',
            '/api/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/reports+json'],
            json_encode($payload),
        );

        $response->assertNoContent();
    }

    #[Test]
    public function it_logs_violations_from_any_origin_not_just_app_url(): void
    {
        // Browser extensions or edge proxies (e.g. anti-bot scripts) can
        // trigger violations attributed to URIs outside our app domain.
        // We still want to record them.
        $report = [
            'csp-report' => [
                'document-uri'        => 'https://example.com/somewhere',
                'effective-directive' => 'script-src',
                'violated-directive'  => 'script-src',
                'blocked-uri'         => 'eval',
                'original-policy'     => "default-src 'none'",
            ],
        ];

        $response = $this->call(
            'POST',
            '/api/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/csp-report'],
            json_encode($report),
        );

        $response->assertNoContent();
    }

    #[Test]
    public function it_rejects_unknown_content_type(): void
    {
        $response = $this->postJson('/api/csp-report', ['csp-report' => ['document-uri' => 'https://snipto.net/']]);

        $response->assertStatus(400);
    }

    #[Test]
    public function it_rejects_recognised_content_type_with_unrecognisable_body(): void
    {
        $response = $this->call(
            'POST',
            '/api/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/csp-report'],
            json_encode(['something-else' => 'here']),
        );

        $response->assertStatus(400);
    }
}
