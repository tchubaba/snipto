<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProtectionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tiered rate limiting on the two snippet API endpoints.
 *
 * These tests pin the rules production runs with, in config/tiered-throttle.php,
 * and drive each one through escalation to lockout. The numbers are duplicated
 * here on purpose: if a tier is loosened in config without a matching change
 * here, the suite fails and the change has to be argued for.
 *
 * WHY setUp() PINS THE BYPASS ALLOWLIST
 * -------------------------------------
 * config/tiered-throttle.php sets bypass_private_ranges so local and Docker
 * development can hammer the API without tripping a tier. That lever — and the
 * 'enabled' kill switch — only applies to environments listed in
 * 'bypass_environments', and phpunit.xml puts the test app in 'testing', which is
 * on that list. Test requests arrive from 127.0.0.1, a loopback address, so left
 * as-is the middleware would wave every request through and every 429 assertion
 * below would fail. Narrowing the allowlist to 'production' reproduces exactly
 * what a live host does: the environment gate fails, no bypass applies, and the
 * limiters are enforced.
 *
 * The last two tests pin down which address the limiter keys on. With no proxy
 * trusted — the shipped default — a rotating X-Forwarded-For cannot buy a fresh
 * budget. With a proxy trusted, the key becomes the forwarded client, so one
 * visitor cannot spend the whole site's allowance.
 */
class ApiRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * GET /api/snipto/{slug} — 20/min, then 10/2min, then 5/5min, then 12h lockout.
     */
    private const SHOW_LIMITER = [
        'tiers'           => [[20, 60], [10, 120], [5, 300]],
        'lockout_seconds' => 43200,
        'offense_ttl'     => 7200,
    ];

    /**
     * POST /api/snipto — 10/min, then 5/5min, then 12h lockout.
     */
    private const STORE_LIMITER = [
        'tiers'           => [[10, 60], [5, 300]],
        'lockout_seconds' => 43200,
        'offense_ttl'     => 7200,
    ];

    private const MISSING_SLUG = 'no-such-snipto';

    private int $created = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tiered-throttle.bypass_environments' => ['production']]);
    }

    protected function tearDown(): void
    {
        // TrustProxies::at() and ::withHeaders() write static state that outlives
        // a single test, so a proxy trusted by one would be trusted by every test
        // afterwards — quietly changing which IP the limiter keys on.
        TrustProxies::flushState();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // The configuration itself
    // ---------------------------------------------------------------------

    #[Test]
    public function the_limiters_hold_the_rules_production_runs_with(): void
    {
        $this->assertSame(self::SHOW_LIMITER, config('tiered-throttle.limiters.show-snipto'));
        $this->assertSame(self::STORE_LIMITER, config('tiered-throttle.limiters.store-snipto'));
    }

    #[Test]
    public function production_is_never_allowed_to_bypass_throttling(): void
    {
        // Read the file as shipped rather than the merged runtime config: setUp()
        // narrows this very key to make the enforcement tests below behave like a
        // live host, so asserting against config() here would prove nothing.
        $shipped = require base_path('config/tiered-throttle.php');

        // Every development lever in the config is gated on this list. Production
        // must stay off it, and an unrecognised environment name must stay off it
        // too — that is what makes the gate an allowlist rather than a denylist.
        $this->assertSame(['local', 'testing'], $shipped['bypass_environments']);

        $this->assertNotContains('production', $shipped['bypass_environments']);
        $this->assertNotContains('prod', $shipped['bypass_environments']);
        $this->assertNotContains('*', $shipped['bypass_environments']);
    }

    // ---------------------------------------------------------------------
    // The development escape hatches, and the gate that bounds them
    // ---------------------------------------------------------------------

    #[Test]
    public function a_private_range_ip_is_still_throttled_when_the_environment_is_not_allowlisted(): void
    {
        // The committed config exempts private ranges. On a host whose environment
        // is not in bypass_environments — which is every deployed host — that
        // exemption must not apply, even though the request here comes from
        // 127.0.0.1 exactly as a Docker bridge request would.
        config(['tiered-throttle.bypass_private_ranges' => true]);

        $this->allowReads(20);
        $this->readBlocked();
    }

    #[Test]
    public function a_private_range_ip_is_exempt_only_while_the_environment_is_allowlisted(): void
    {
        // The local/Docker affordance the config is set up for: 127.0.0.1 is
        // exempt, so development can hammer the API without escalating a tier.
        config([
            'tiered-throttle.bypass_private_ranges' => true,
            'tiered-throttle.bypass_environments'   => ['local', 'testing'],
        ]);

        $this->allowReads(60);
        $this->allowCreates(20);
    }

    #[Test]
    public function the_kill_switch_disables_throttling_only_in_allowlisted_environments(): void
    {
        config([
            'tiered-throttle.enabled'             => false,
            'tiered-throttle.bypass_environments' => ['local', 'testing'],
        ]);

        $this->allowReads(60);

        // Same flag, environment not allowlisted: the limiter stays on. A stray
        // TIERED_THROTTLE_ENABLED=false must never take rate limiting offline.
        config(['tiered-throttle.bypass_environments' => ['production']]);

        $this->allowReads(20);
        $this->readBlocked();
    }

    // ---------------------------------------------------------------------
    // GET /api/snipto/{slug} — show-snipto
    // ---------------------------------------------------------------------

    #[Test]
    public function show_snipto_allows_twenty_reads_a_minute_then_blocks_with_a_reset_hint(): void
    {
        $this->allowReads(20);

        $blocked = $this->readBlocked();
        $blocked->assertHeader('X-RateLimit-Reset');
        $blocked->assertHeader('Retry-After');
        $blocked->assertJson(['message' => 'Too Many Attempts.']);
    }

    #[Test]
    public function show_snipto_drops_to_ten_reads_per_two_minutes_after_an_offense(): void
    {
        $this->allowReads(20);
        $this->readBlocked();

        // The next window opens on the next tier, not on the same allowance.
        $this->travel(61)->seconds();

        $this->allowReads(10);
        $this->readBlocked();
    }

    #[Test]
    public function show_snipto_locks_the_ip_out_once_all_three_tiers_are_exhausted(): void
    {
        // Offense 1: tier 0 spent.
        $this->allowReads(20);
        $this->readBlocked();

        // Offense 2: tier 1 spent.
        $this->travel(61)->seconds();
        $this->allowReads(10);
        $this->readBlocked();

        // Tier 2 gets 5 reads per 5 minutes; the 6th is the third offense.
        $this->travel(125)->seconds();
        $this->allowReads(5);

        $locked = $this->getJson('/api/snipto/'.self::MISSING_SLUG);
        $locked->assertStatus(429)->assertHeader('X-RateLimit-Lockout', '1');

        // Lockout is 12 hours, so a fresh window does not let the IP back in.
        $this->travel(301)->seconds();

        $this->getJson('/api/snipto/'.self::MISSING_SLUG)
            ->assertStatus(429)
            ->assertHeader('X-RateLimit-Lockout', '1');
    }

    #[Test]
    public function show_snipto_forgives_an_offense_once_the_offense_ttl_passes(): void
    {
        $this->allowReads(20);
        $this->readBlocked();

        // Two clean hours later the offense counter is gone and the IP is back on
        // the baseline tier rather than the stricter one it earned.
        $this->travel(7201)->seconds();

        $this->allowReads(20);
        $this->readBlocked();
    }

    // ---------------------------------------------------------------------
    // POST /api/snipto — store-snipto
    // ---------------------------------------------------------------------

    #[Test]
    public function store_snipto_allows_ten_creates_a_minute_then_blocks_the_eleventh(): void
    {
        $this->allowCreates(10);

        $blocked = $this->createBlocked();
        $blocked->assertHeader('X-RateLimit-Reset');
        $blocked->assertHeader('Retry-After');
    }

    #[Test]
    public function store_snipto_locks_the_ip_out_once_both_tiers_are_exhausted(): void
    {
        // Offense 1 spends tier 0 (10/min).
        $this->allowCreates(10);
        $this->createBlocked();

        // Tier 1 gets 5 creates per 5 minutes; the 6th is the second offense, and
        // with only two tiers defined that is the lockout.
        $this->travel(61)->seconds();
        $this->allowCreates(5);

        $locked = $this->createSnipto();
        $locked->assertStatus(429)->assertHeader('X-RateLimit-Lockout', '1');

        $this->travel(301)->seconds();

        $this->createSnipto()
            ->assertStatus(429)
            ->assertHeader('X-RateLimit-Lockout', '1');
    }

    // ---------------------------------------------------------------------
    // Limiter isolation
    // ---------------------------------------------------------------------

    #[Test]
    public function exhausting_the_create_limiter_leaves_the_read_limiter_alone(): void
    {
        $this->allowCreates(10);
        $this->createBlocked();

        // Creating as fast as possible has not touched the read budget.
        $this->getJson('/api/snipto/'.self::MISSING_SLUG)->assertNotFound();
    }

    #[Test]
    public function exhausting_the_read_limiter_leaves_the_create_limiter_alone(): void
    {
        $this->allowReads(20);
        $this->readBlocked();

        // Reading as fast as possible has not touched the create budget.
        $this->createSnipto()->assertOk();
    }

    // ---------------------------------------------------------------------
    // Which address the limiter keys on
    // ---------------------------------------------------------------------

    #[Test]
    public function a_forged_forwarded_for_header_cannot_dodge_the_limiter(): void
    {
        // Nothing is trusted by default, so X-Forwarded-For is never consulted and
        // every request keys on the connecting address regardless of what it
        // claims. This is the property that makes the shipped default safe: an
        // attacker cannot buy a fresh rate-limit budget by rotating a header.
        $this->assertSame('127.0.0.1', $this->resolvedClientIp(['X-Forwarded-For' => '203.0.113.7']));

        for ($i = 0; $i < 20; $i++) {
            $this->readFromClient('203.0.113.'.($i + 1))->assertNotFound();
        }

        $this->readFromClient('203.0.113.99')->assertStatus(429);
    }

    #[Test]
    public function the_limiter_keys_on_the_forwarded_client_once_the_proxy_is_trusted(): void
    {
        // The self-hosted topology DOCKERHUB.md describes: a proxy in front, so the
        // connecting address is the proxy's and the client arrives in the header.
        // In tests the connecting peer is 127.0.0.1, which stands in for it.
        TrustProxies::at('127.0.0.1');
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR);

        $this->assertSame('203.0.113.7', $this->resolvedClientIp(['X-Forwarded-For' => '203.0.113.7']));

        for ($i = 0; $i < 20; $i++) {
            $this->readFromClient('203.0.113.7')->assertNotFound();
        }

        // That client has spent its tier-0 allowance ...
        $this->readFromClient('203.0.113.7')->assertStatus(429);

        // ... and its neighbour behind the same proxy is untouched. That is the
        // point of trusting the header: without it, everyone behind the proxy
        // shares one key and one visitor spends the whole site's budget.
        $this->readFromClient('198.51.100.4')->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Read a slug that does not exist, expecting the controller's 404 every time.
     * The slug never exists, so the limiter is the only thing that can change the
     * status code away from 404.
     */
    private function allowReads(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->getJson('/api/snipto/'.self::MISSING_SLUG)->assertNotFound();
        }
    }

    private function readBlocked(): TestResponse
    {
        $response = $this->getJson('/api/snipto/'.self::MISSING_SLUG);
        $response->assertStatus(429);

        return $response;
    }

    /**
     * Create a plaintext snippet, expecting a fresh row each time.
     */
    private function allowCreates(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->createSnipto()->assertOk();
        }
    }

    private function createBlocked(): TestResponse
    {
        $response = $this->createSnipto();
        $response->assertStatus(429);

        return $response;
    }

    private function createSnipto(): TestResponse
    {
        $this->created++;

        return $this->postJson('/api/snipto', [
            'slug'            => 'rl-'.$this->created,
            'payload'         => 'Hello World',
            'protection_type' => ProtectionType::Plaintext->value,
        ]);
    }

    /**
     * Read the show endpoint as one particular client, per X-Forwarded-For.
     * No assertion here: the caller decides whether that client is allowed.
     */
    private function readFromClient(string $clientIp): TestResponse
    {
        return $this->getJson('/api/snipto/'.self::MISSING_SLUG, [
            'X-Forwarded-For' => $clientIp,
        ]);
    }

    /**
     * The address $request->ip() resolves to for a request carrying the given
     * headers. Deliberately registered without the throttle middleware, so
     * probing it costs no rate-limit budget. Two path segments keep it clear of
     * the /{slug} catch-all in routes/web.php.
     */
    private function resolvedClientIp(array $headers = []): string
    {
        Route::get('/smoke/client-ip', fn (Request $request) => $request->ip());

        return (string) $this->get('/smoke/client-ip', $headers)->getContent();
    }
}
