<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProtectionType;
use App\Models\Snipto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Storage hygiene (audit finding F-3): expired rows must not linger in the
 * database — plaintext-mode payloads in particular stay at rest until
 * deleted. Deletion runs via the sniptos:purge command, registered on the
 * scheduler in bootstrap/app.php (every 15 minutes) and ticked by the
 * `schedule:work` supervisor the shipped Docker entrypoints start.
 */
class PurgeExpiredSniptosTest extends TestCase
{
    use RefreshDatabase;

    private function makeSnipto(string $slug, Carbon $expiresAt): Snipto
    {
        return Snipto::create([
            'slug'            => $slug,
            'payload'         => 'payload-' . $slug,
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => $expiresAt,
            'views_remaining' => 1,
        ]);
    }

    #[Test]
    public function purge_command_deletes_expired_rows_and_keeps_live_ones(): void
    {
        $this->makeSnipto('expired-a', Carbon::now()->subMinute());
        $this->makeSnipto('expired-b', Carbon::now()->subDay());
        $this->makeSnipto('live-a', Carbon::now()->addHour());

        // Boundary: expires_at exactly now() counts as expired (matches the
        // <= comparison used by reads).
        $this->makeSnipto('expired-boundary', Carbon::now());

        $this->artisan('sniptos:purge')
            ->expectsOutputToContain('Purged 3 expired snipto(s).')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('sniptos', ['slug' => 'expired-a']);
        $this->assertDatabaseMissing('sniptos', ['slug' => 'expired-b']);
        $this->assertDatabaseMissing('sniptos', ['slug' => 'expired-boundary']);
        $this->assertDatabaseHas('sniptos', ['slug' => 'live-a']);
    }

    #[Test]
    public function purge_command_is_idempotent_on_an_empty_table(): void
    {
        $this->artisan('sniptos:purge')
            ->expectsOutputToContain('Purged 0 expired snipto(s).')
            ->assertExitCode(0);
    }

    #[Test]
    public function creation_does_not_run_hygiene_queries(): void
    {
        // The API must not piggyback purge-style sweeps onto store() (audit
        // follow-up): unrelated queries add DB load and latency variance to
        // the creation path. Creating a snipto leaves both expired and live
        // rows untouched — only the scheduled purge deletes.
        $this->makeSnipto('expired-row', Carbon::now()->subMinute());
        $this->makeSnipto('live-row', Carbon::now()->addHour());

        $response = $this->postJson('/api/snipto', [
            'slug'            => 'fresh-row',
            'payload'         => 'Hello World',
            'protection_type' => ProtectionType::Plaintext->value,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseHas('sniptos', ['slug' => 'expired-row']);
        $this->assertDatabaseHas('sniptos', ['slug' => 'live-row']);
        $this->assertDatabaseHas('sniptos', ['slug' => 'fresh-row']);
    }
}
