<?php

namespace Tests\Feature;

use App\Enums\ProtectionType;
use App\Models\Snipto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_applies_cross_origin_isolation_headers_to_web_routes()
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
    }

    #[Test]
    public function it_applies_cross_origin_isolation_headers_to_snippet_view()
    {
        Snipto::create([
            'slug'            => 'test-slug',
            'payload'         => 'some data',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 1,
        ]);

        $response = $this->get('/test-slug');

        $response->assertStatus(200);
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
    }

    #[Test]
    public function it_applies_csp_headers()
    {
        // Force HTTPS and clear config cache to ensure middleware runs
        config(['csp.enabled' => true]);

        $response = $this->get('/', [
            'HTTPS' => 'on',
        ]);

        $response->assertHeader('Content-Security-Policy');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("require-trusted-types-for 'script'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);

        // Audit finding F-5: no framed content at all (srcdoc iframes are
        // exempt from frame-src, so this only blocks injected external
        // frames), and modern report-to reporting wired end to end.
        $this->assertStringContainsString("frame-src 'none'", $csp);
        $this->assertStringContainsString('report-to snipto', $csp);
    }

    #[Test]
    public function it_maps_the_report_to_group_to_the_collector_endpoint(): void
    {
        config(['csp.enabled' => true]);

        $response = $this->get('/');

        // Chrome 118+ needs the Reporting-Endpoints header to resolve the
        // `report-to snipto` group; without it CSP reports go nowhere.
        $response->assertHeader(
            'Reporting-Endpoints',
            'snipto="' . rtrim((string) config('app.url'), '/') . '/api/csp-report"'
        );
    }

    #[Test]
    public function it_emits_no_cors_headers_on_api_routes(): void
    {
        // Audit finding F-1: Laravel's framework CORS defaults (paths
        // ['api/*'], allowed_origins ['*']) were live on production.
        // config/cors.php pins paths => []; nothing may echo
        // Access-Control-Allow-Origin or answer a cross-origin preflight
        // with CORS grants.
        $preflight = $this->withHeaders([
            'Origin'                        => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/snipto/abcd1234');

        $this->assertNull($preflight->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($preflight->headers->get('Access-Control-Allow-Methods'));

        $probe = $this->withHeaders(['Origin' => 'https://evil.example'])
            ->getJson('/api/snipto/abcd1234');

        $this->assertNull($probe->headers->get('Access-Control-Allow-Origin'));
    }
}
