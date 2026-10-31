<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\ApiController;
use App\Models\Snipto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Write-count hygiene for reveal() (the endpoint every viewer hits).
 *
 * A single-use snipto that reveals successfully is a row this transaction
 * deletes. It must therefore die with exactly one write — SELECT + DELETE —
 * and never churn the doomed row with the token-consumption UPDATE or the
 * view-decrement UPDATE (the pre-optimization shape was SELECT + UPDATE +
 * UPDATE + DELETE; measured with DB::listen). Failed attempts keep the row
 * and must still burn the token, in exactly one UPDATE.
 */
class RevealWriteCountTest extends TestCase
{
    private function makeSnipto(array $overrides = []): Snipto
    {
        return Snipto::create(array_merge([
            'slug'            => 'rwct' . bin2hex(random_bytes(4)),
            'payload'         => 'ciphertext',
            'nonce'           => str_repeat('ab', 12),
            'key_hash'        => str_repeat('01', 32),
            'views_remaining' => 1,
            'expires_at'      => now()->addHour(),
        ], $overrides));
    }

    /**
     * Run $fn while capturing every SQL statement that touches `sniptos`.
     *
     * @return array{0: list<string>, 1: mixed}
     */
    private function captureSniptoSql(callable $fn): array
    {
        $sql = [];
        DB::listen(static function ($q) use (&$sql): void {
            if (str_contains($q->sql, 'sniptos')) {
                $sql[] = strtolower($q->sql);
            }
        });

        $result = $fn();

        // NOTE: read $sql AFTER $fn() — array literals evaluate left-to-right,
        // so `return [$sql, $fn()]` would capture $sql before it was populated.
        return [$sql, $result];
    }

    private function revealTokenFor(ApiController $controller, string $slug): string
    {
        return $controller->show($slug)->getData(true)['reveal_token'];
    }

    public function test_successful_single_use_reveal_deletes_the_row_without_updating_it(): void
    {
        $snipto     = $this->makeSnipto();
        $controller = app(ApiController::class);

        $token = $this->revealTokenFor($controller, $snipto->slug);

        [$sql, $response] = $this->captureSniptoSql(
            fn () => $controller->reveal($snipto->slug, Request::create('/reveal', 'POST', [
                'reveal_token' => $token,
                'key_hash'     => str_repeat('01', 32),
            ]))
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ciphertext', $response->getData(true)['payload']);
        $this->assertSame(0, $response->getData(true)['views_remaining']);

        $updates = array_values(array_filter($sql, static fn (string $s): bool => str_starts_with(trim($s), 'update')));
        $deletes = array_values(array_filter($sql, static fn (string $s): bool => str_starts_with(trim($s), 'delete')));

        $this->assertCount(0, $updates, 'a successful single-use reveal must UPDATE nothing — the row dies: ' . implode(' || ', $sql));
        $this->assertCount(1, $deletes);
        $this->assertNull(Snipto::find($snipto->id));
    }

    public function test_failed_key_hash_attempt_burns_the_token_in_one_update_and_keeps_the_row(): void
    {
        $snipto     = $this->makeSnipto();
        $controller = app(ApiController::class);

        $token = $this->revealTokenFor($controller, $snipto->slug);

        [$sql, $response] = $this->captureSniptoSql(
            fn () => $controller->reveal($snipto->slug, Request::create('/reveal', 'POST', [
                'reveal_token' => $token,
                'key_hash'     => str_repeat('ff', 32),
            ]))
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Key Hash invalid.', $response->getData(true)['message']);

        $updates = array_values(array_filter($sql, static fn (string $s): bool => str_starts_with(trim($s), 'update')));
        $this->assertCount(1, $updates, 'failed attempts burn the token with exactly one UPDATE: ' . implode(' || ', $sql));

        $fresh = Snipto::find($snipto->id);
        $this->assertNotNull($fresh, 'a wrong key_hash must never consume the snippet itself');
        $this->assertNull($fresh->reveal_token_hash, 'the token must be burned');
        $this->assertSame(1, $fresh->views_remaining);
    }
}
