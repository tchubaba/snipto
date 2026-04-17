<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProtectionType;
use App\Models\Snipto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProgressiveThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '1.2.3.4';

    private const OTHER_IP = '9.8.7.6';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rate_limiting.limiters.show-snipto' => [
                'tiers' => [
                    [10, 60],
                    [10, 120],
                    [5, 300],
                ],
                'lockout_seconds' => 43200,
                'offense_ttl'     => 86400,
            ],
            'rate_limiting.limiters.store-snipto' => [
                'tiers' => [
                    [10, 60],
                    [5, 300],
                ],
                'lockout_seconds' => 43200,
                'offense_ttl'     => 86400,
            ],
        ]);

        Cache::flush();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createSnippet(string $slug = 'test-slug'): void
    {
        Snipto::firstOrCreate(['slug' => $slug], [
            'payload'         => 'Hello',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 100,
        ]);
    }

    private function hitShow(int $n, string $ip = self::IP, string $slug = 'test-slug'): array
    {
        $responses = [];
        for ($i = 0; $i < $n; $i++) {
            $responses[] = $this
                ->withServerVariables(['REMOTE_ADDR' => $ip])
                ->getJson("/api/snipto/{$slug}");
        }

        return $responses;
    }

    private function hitStore(int $n, string $ip = self::IP): array
    {
        $responses = [];
        for ($i = 0; $i < $n; $i++) {
            $responses[] = $this
                ->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/snipto', [
                    'slug'            => 'store-slug-'.uniqid(),
                    'payload'         => 'Hello World',
                    'protection_type' => ProtectionType::Plaintext->value,
                ]);
        }

        return $responses;
    }

    private function lastStatus(array $responses): int
    {
        return end($responses)->status();
    }

    // -------------------------------------------------------------------------
    // Basic tier 0 behaviour
    // -------------------------------------------------------------------------

    #[Test]
    public function it_allows_requests_within_first_tier_limit(): void
    {
        $this->createSnippet();

        $responses = $this->hitShow(10);

        foreach ($responses as $i => $response) {
            $this->assertNotEquals(429, $response->status(), 'Request #'.($i + 1).' unexpectedly returned 429');
        }
    }

    #[Test]
    public function it_blocks_on_the_eleventh_request_in_tier_zero(): void
    {
        $this->createSnippet();

        $responses = $this->hitShow(11);

        $this->assertEquals(429, $this->lastStatus($responses));
    }

    #[Test]
    public function it_returns_x_ratelimit_reset_header_on_throttle(): void
    {
        $this->createSnippet();

        $responses = $this->hitShow(11);
        $last      = end($responses);

        $last->assertStatus(429);
        $this->assertNotEmpty($last->headers->get('X-RateLimit-Reset'));
        $this->assertGreaterThan(time(), (int) $last->headers->get('X-RateLimit-Reset'));
    }

    #[Test]
    public function it_does_not_include_lockout_header_on_normal_throttle(): void
    {
        $this->createSnippet();

        $responses = $this->hitShow(11);
        $last      = end($responses);

        $last->assertStatus(429);
        $this->assertNull($last->headers->get('X-RateLimit-Lockout'));
    }

    // -------------------------------------------------------------------------
    // Single escalation per window
    // -------------------------------------------------------------------------

    #[Test]
    public function it_only_escalates_offense_count_once_per_window(): void
    {
        $this->createSnippet();

        // Exhaust tier 0 and hammer (11 → offense escalates to 1)
        $this->hitShow(20); // all in same 60s window — only 1 escalation

        // Travel past tier 0 window (60 seconds)
        $this->travel(61)->seconds();

        // Now in tier 1: 10 attempts per 120 seconds — should still have 10 attempts
        $responses = $this->hitShow(10);
        foreach ($responses as $response) {
            $this->assertNotEquals(429, $response->status());
        }

        // 11th in tier 1 should be blocked
        $blocked = $this->hitShow(1);
        $this->assertEquals(429, $this->lastStatus($blocked));
    }

    // -------------------------------------------------------------------------
    // Tier escalation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_escalates_to_second_tier_after_first_offense(): void
    {
        $this->createSnippet();

        // Exhaust tier 0 (offense → 1)
        $this->hitShow(11);
        $this->travel(61)->seconds(); // tier 0 window expires

        // Tier 1: 10 attempts / 120 seconds
        $responses = $this->hitShow(10);
        foreach ($responses as $response) {
            $this->assertNotEquals(429, $response->status());
        }

        // 11th should block (tier 1 exhausted)
        $blocked = $this->hitShow(1);
        $this->assertEquals(429, $this->lastStatus($blocked));
        $this->assertNull(end($blocked)->headers->get('X-RateLimit-Lockout'));
    }

    #[Test]
    public function it_escalates_to_third_tier_after_second_offense(): void
    {
        $this->createSnippet();

        // Exhaust tier 0
        $this->hitShow(11);
        $this->travel(61)->seconds();

        // Exhaust tier 1
        $this->hitShow(11);
        $this->travel(121)->seconds();

        // Tier 2: 5 attempts / 300 seconds
        $responses = $this->hitShow(5);
        foreach ($responses as $response) {
            $this->assertNotEquals(429, $response->status());
        }

        // 6th should block (and triggers lockout since it exhausts all 3 tiers)
        $blocked = $this->hitShow(1);
        $this->assertEquals(429, $this->lastStatus($blocked));
    }

    // -------------------------------------------------------------------------
    // Lockout
    // -------------------------------------------------------------------------

    #[Test]
    public function it_locks_out_after_all_tiers_exhausted(): void
    {
        $this->createSnippet();

        // Exhaust tier 0
        $this->hitShow(11);
        $this->travel(61)->seconds();

        // Exhaust tier 1
        $this->hitShow(11);
        $this->travel(121)->seconds();

        // Exhaust tier 2 (offense → 3, which >= 3 tiers → lockout)
        $responses = $this->hitShow(6);
        $last      = end($responses);

        $last->assertStatus(429);
        $this->assertEquals('1', $last->headers->get('X-RateLimit-Lockout'));
        $this->assertNull($last->headers->get('X-RateLimit-Reset'));
    }

    #[Test]
    public function it_maintains_lockout_for_12_hours(): void
    {
        $this->createSnippet();

        // Trigger lockout
        $this->hitShow(11);
        $this->travel(61)->seconds();
        $this->hitShow(11);
        $this->travel(121)->seconds();
        $this->hitShow(6);

        // 11h 59m later — still locked
        $this->travel(11 * 3600 + 59 * 60)->seconds();
        $response = $this->hitShow(1);
        $this->assertEquals(429, $this->lastStatus($response));
        $this->assertEquals('1', end($response)->headers->get('X-RateLimit-Lockout'));

        // 1 more minute (total 12h) — lockout expires
        $this->travel(61)->seconds();
        $response = $this->hitShow(1);
        $this->assertNotEquals(429, $this->lastStatus($response));
    }

    // -------------------------------------------------------------------------
    // Offense counter TTL reset
    // -------------------------------------------------------------------------

    #[Test]
    public function it_resets_offense_count_after_24_hours(): void
    {
        $this->createSnippet();

        // Accumulate 2 offenses
        $this->hitShow(11);
        $this->travel(61)->seconds();
        $this->hitShow(11);
        $this->travel(121)->seconds();

        // Travel past 24h offense TTL
        $this->travel(86401)->seconds();

        // Should be back to tier 0: 10 allowed
        $responses = $this->hitShow(10);
        foreach ($responses as $response) {
            $this->assertNotEquals(429, $response->status());
        }

        // 11th triggers throttle (tier 0), not lockout
        $blocked = $this->hitShow(1);
        $this->assertEquals(429, $this->lastStatus($blocked));
        $this->assertNull(end($blocked)->headers->get('X-RateLimit-Lockout'));
    }

    // -------------------------------------------------------------------------
    // Limiter isolation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_uses_independent_limiters_for_show_and_store(): void
    {
        $this->createSnippet();

        // Lock out show-snipto
        $this->hitShow(11);
        $this->travel(61)->seconds();
        $this->hitShow(11);
        $this->travel(121)->seconds();
        $this->hitShow(6);

        // show-snipto is locked
        $showResponse = $this->hitShow(1);
        $this->assertEquals(429, $this->lastStatus($showResponse));

        // store-snipto should still work
        $storeResponse = $this->hitStore(1);
        $this->assertNotEquals(429, $this->lastStatus($storeResponse));
    }

    #[Test]
    public function it_does_not_affect_csp_report_route(): void
    {
        $this->createSnippet();

        // Lock out show-snipto
        $this->hitShow(11);
        $this->travel(61)->seconds();
        $this->hitShow(11);
        $this->travel(121)->seconds();
        $this->hitShow(6);

        // CSP report route is unaffected
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->postJson('/api/csp-report', ['csp-report' => []]);

        $this->assertNotEquals(429, $response->status());
    }

    #[Test]
    public function it_tracks_limits_per_ip_independently(): void
    {
        $this->createSnippet();

        // Lock out IP 1
        $this->hitShow(11, self::IP);
        $this->travel(61)->seconds();
        $this->hitShow(11, self::IP);
        $this->travel(121)->seconds();
        $this->hitShow(6, self::IP);

        $response = $this->hitShow(1, self::IP);
        $this->assertEquals(429, $this->lastStatus($response));

        // IP 2 is unaffected
        $response2 = $this->hitShow(1, self::OTHER_IP);
        $this->assertNotEquals(429, $this->lastStatus($response2));
    }

    // -------------------------------------------------------------------------
    // Unban artisan command
    // -------------------------------------------------------------------------

    #[Test]
    public function it_unban_command_clears_lockout(): void
    {
        $this->createSnippet();

        // Trigger lockout
        $this->hitShow(11);
        $this->travel(61)->seconds();
        $this->hitShow(11);
        $this->travel(121)->seconds();
        $this->hitShow(6);

        $locked = $this->hitShow(1);
        $this->assertEquals(429, $this->lastStatus($locked));

        // Run unban command
        Artisan::call('snipto:unban', ['ip' => self::IP]);

        // Should be allowed again
        $response = $this->hitShow(1);
        $this->assertNotEquals(429, $this->lastStatus($response));
    }

    #[Test]
    public function it_unban_command_resets_offense_count_to_zero(): void
    {
        $this->createSnippet();

        // Accumulate 2 offenses
        $this->hitShow(11);
        $this->travel(61)->seconds();
        $this->hitShow(11);
        $this->travel(121)->seconds();

        // Unban
        Artisan::call('snipto:unban', ['ip' => self::IP]);

        // Should be back at tier 0: 10 allowed
        $responses = $this->hitShow(10);
        foreach ($responses as $response) {
            $this->assertNotEquals(429, $response->status());
        }
    }

    #[Test]
    public function it_unban_command_with_limiter_option_only_clears_that_limiter(): void
    {
        $this->createSnippet();

        // Lock out show-snipto
        $this->hitShow(11);
        $this->travel(61)->seconds();
        $this->hitShow(11);
        $this->travel(121)->seconds();
        $this->hitShow(6);

        // Lock out store-snipto
        $this->hitStore(11);
        $this->travel(61)->seconds();
        $this->hitStore(6);

        // Unban only show-snipto
        Artisan::call('snipto:unban', ['ip' => self::IP, '--limiter' => 'show-snipto']);

        // show-snipto is unblocked
        $showResponse = $this->hitShow(1);
        $this->assertNotEquals(429, $this->lastStatus($showResponse));

        // store-snipto is still locked
        $storeResponse = $this->hitStore(1);
        $this->assertEquals(429, $this->lastStatus($storeResponse));
        $this->assertEquals('1', end($storeResponse)->headers->get('X-RateLimit-Lockout'));
    }
}
