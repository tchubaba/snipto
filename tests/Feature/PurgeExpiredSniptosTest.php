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

    #[Test]
    public function purge_reaps_abandoned_file_uploads_but_not_in_flight_ones(): void
    {
        // A file-mode row whose PUT never landed can never complete once its
        // upload token dies (30 min) — reap it instead of squatting until the
        // full expiration window.
        $abandoned = Snipto::create([
            'slug'                    => 'abandoned-upload',
            'kind'                    => 'file',
            'payload'                 => null,
            'protection_type'         => ProtectionType::Secret,
            'expires_at'              => Carbon::now()->addHour(),
            'upload_token_expires_at' => Carbon::now()->subMinute(),
            'views_remaining'         => 1,
        ]);
        $abandoned->created_at = Carbon::now()->subMinutes(31);
        $abandoned->save();

        // A reset row whose original created_at was > 30 min ago but whose
        // upload_token_expires_at is still in the future must NOT be reaped
        // while the retried upload is in flight.
        $resetInFlight = Snipto::create([
            'slug'                    => 'reset-in-flight',
            'kind'                    => 'file',
            'payload'                 => null,
            'protection_type'         => ProtectionType::Secret,
            'expires_at'              => Carbon::now()->addHour(),
            'upload_token_expires_at' => Carbon::now()->addMinutes(20),
            'views_remaining'         => 1,
        ]);
        $resetInFlight->created_at = Carbon::now()->subMinutes(40);
        $resetInFlight->save();

        // An in-flight upload (created after the TTL window opened) must keep
        // its row — the creator's 50 MiB PUT may still be landing.
        $inFlight = Snipto::create([
            'slug'                    => 'in-flight-upload',
            'kind'                    => 'file',
            'payload'                 => null,
            'protection_type'         => ProtectionType::Secret,
            'expires_at'              => Carbon::now()->addHour(),
            'upload_token_expires_at' => Carbon::now()->addMinutes(20),
            'views_remaining'         => 1,
        ]);
        $inFlight->created_at = Carbon::now()->subMinutes(10);
        $inFlight->save();

        // A completed file row well inside its window stays untouched.
        $completed = Snipto::create([
            'slug'            => 'completed-file',
            'kind'            => 'file',
            'payload'         => null,
            'payload_blob'    => 'opaque-ciphertext',
            'protection_type' => ProtectionType::Secret,
            'expires_at'      => Carbon::now()->addDay(),
            'views_remaining' => 1,
        ]);

        $this->artisan('sniptos:purge')
            ->expectsOutputToContain('Purged 1 expired snipto(s).')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('sniptos', ['slug' => 'abandoned-upload']);
        $this->assertDatabaseHas('sniptos', ['slug' => 'reset-in-flight']);
        $this->assertDatabaseHas('sniptos', ['slug' => 'in-flight-upload']);
        $this->assertDatabaseHas('sniptos', ['slug' => 'completed-file']);
    }
}
