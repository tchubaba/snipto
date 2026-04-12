<?php

namespace Tests\Feature;

use App\Models\Snipto;
use App\Enums\ProtectionType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

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
    }
}
