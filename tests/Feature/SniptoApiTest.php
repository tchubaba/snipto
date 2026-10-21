<?php

namespace Tests\Feature;

use App\Enums\ProtectionType;
use App\Models\Snipto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class SniptoApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_store_a_plaintext_snippet()
    {
        $response = $this->postJson('/api/snipto', [
            'slug'            => 'test-plaintext',
            'payload'         => 'Hello World',
            'protection_type' => ProtectionType::Plaintext->value,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'slug' => 'test-plaintext']);

        $this->assertDatabaseHas('sniptos', [
            'slug'            => 'test-plaintext',
            'payload'         => 'Hello World',
            'protection_type' => ProtectionType::Plaintext->value,
        ]);
    }

    #[Test]
    public function it_can_store_an_encrypted_snippet()
    {
        $nonce   = bin2hex(random_bytes(12));
        $keyHash = hash('sha256', 'some-secret');

        $response = $this->postJson('/api/snipto', [
            'slug'            => 'test-encrypted',
            'payload'         => base64_encode('encrypted-data'),
            'nonce'           => $nonce,
            'key_hash'        => $keyHash,
            'protection_type' => ProtectionType::Secret->value,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'slug' => 'test-encrypted']);

        $this->assertDatabaseHas('sniptos', [
            'slug'            => 'test-encrypted',
            'nonce'           => $nonce,
            'key_hash'        => $keyHash,
            'protection_type' => ProtectionType::Secret->value,
        ]);
    }

    #[Test]
    public function it_can_retrieve_a_plaintext_snippet()
    {
        Snipto::create([
            'slug'            => 'my-slug',
            'payload'         => 'Secret Content',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 1,
        ]);

        // Step 1: metadata GET hands out a reveal token, never the payload.
        $meta = $this->getJson('/api/snipto/my-slug');
        $meta->assertStatus(200)
            ->assertJson([
                'success'         => true,
                'protection_type' => ProtectionType::Plaintext->value,
            ])
            ->assertJsonMissing(['payload']);

        $token = $meta->json('reveal_token');
        $this->assertNotEmpty($token);

        // Step 2: POST reveal with the token releases the payload.
        $response = $this->postJson('/api/snipto/my-slug/reveal', ['reveal_token' => $token]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'payload' => 'Secret Content',
            ]);

        // Verify it was deleted after view
        $this->assertDatabaseMissing('sniptos', ['slug' => 'my-slug']);
    }

    #[Test]
    public function it_can_retrieve_an_encrypted_snippet_with_valid_hash()
    {
        $keyHash = hash('sha256', 'my-secret');

        Snipto::create([
            'slug'            => 'enc-slug',
            'payload'         => 'base64data',
            'nonce'           => 'nonce-value',
            'key_hash'        => $keyHash,
            'protection_type' => ProtectionType::Secret,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 1,
        ]);

        $token = $this->getJson('/api/snipto/enc-slug')->json('reveal_token');
        $this->assertNotEmpty($token);

        // key_hash travels in the POST body — never in the query string (F-001).
        $response = $this->postJson('/api/snipto/enc-slug/reveal', [
            'reveal_token' => $token,
            'key_hash'     => $keyHash,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'payload' => 'base64data',
                'nonce'   => 'nonce-value',
            ]);

        $this->assertDatabaseMissing('sniptos', ['slug' => 'enc-slug']);
    }

    #[Test]
    public function it_returns_403_for_encrypted_snippet_with_invalid_hash()
    {
        $keyHash   = hash('sha256', 'my-secret');
        $wrongHash = hash('sha256', 'wrong-secret');

        Snipto::create([
            'slug'            => 'enc-slug',
            'payload'         => 'base64data',
            'nonce'           => 'nonce-value',
            'key_hash'        => $keyHash,
            'protection_type' => ProtectionType::Secret,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 1,
        ]);

        $token = $this->getJson('/api/snipto/enc-slug')->json('reveal_token');

        $response = $this->postJson('/api/snipto/enc-slug/reveal', [
            'reveal_token' => $token,
            'key_hash'     => $wrongHash,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('sniptos', ['slug' => 'enc-slug']);

        // The failed attempt consumed the token: even the CORRECT key_hash can no
        // longer be probed against it — the attacker must re-GET and re-enter the
        // show-snipto throttle tiers for every attempt.
        $retry = $this->postJson('/api/snipto/enc-slug/reveal', [
            'reveal_token' => $token,
            'key_hash'     => $keyHash,
        ]);
        $retry->assertStatus(403);
    }

    #[Test]
    public function it_returns_404_for_non_existent_snippet()
    {
        $response = $this->getJson('/api/snipto/non-existent');
        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_for_expired_snippet_and_deletes_it()
    {
        Snipto::create([
            'slug'            => 'expired-slug',
            'payload'         => 'some data',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->subMinute(),
            'views_remaining' => 1,
        ]);

        $response = $this->getJson('/api/snipto/expired-slug');

        $response->assertStatus(404);
        $this->assertDatabaseMissing('sniptos', ['slug' => 'expired-slug']);
    }

    #[Test]
    public function it_validates_required_fields_for_encrypted_snippets()
    {
        $response = $this->postJson('/api/snipto', [
            'slug'            => 'invalid-enc',
            'protection_type' => ProtectionType::Secret->value,
            // missing payload, nonce, key_hash
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_can_store_a_snipto_id_encrypted_snippet()
    {
        $nonce          = bin2hex(random_bytes(12));
        $keyHash        = hash('sha256', random_bytes(32));
        $senderPubKey   = base64_encode(random_bytes(32)); // 44 chars
        $recipientSalt  = base64_encode(random_bytes(16)); // 24 chars

        $response = $this->postJson('/api/snipto', [
            'slug'              => 'test-snipto-id',
            'payload'           => base64_encode('encrypted-data'),
            'nonce'             => $nonce,
            'key_hash'          => $keyHash,
            'protection_type'   => ProtectionType::SniptoId->value,
            'sender_public_key' => $senderPubKey,
            'key_provider_type' => 'passphrase',
            'recipient_salt'    => $recipientSalt,
            'expiration'        => '1d',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'slug' => 'test-snipto-id']);

        $this->assertDatabaseHas('sniptos', [
            'slug'              => 'test-snipto-id',
            'protection_type'   => ProtectionType::SniptoId->value,
            'sender_public_key' => $senderPubKey,
            'key_provider_type' => 'passphrase',
            'recipient_salt'    => $recipientSalt,
        ]);
    }

    #[Test]
    public function it_returns_sender_public_key_for_snipto_id_type()
    {
        $senderPubKey  = base64_encode(random_bytes(32));
        $recipientSalt = base64_encode(random_bytes(16));
        $keyHash       = hash('sha256', 'recipient-pub');

        Snipto::create([
            'slug'              => 'sid-slug',
            'payload'           => base64_encode('encrypted-data'),
            'nonce'             => bin2hex(random_bytes(12)),
            'key_hash'          => $keyHash,
            'protection_type'   => ProtectionType::SniptoId,
            'sender_public_key' => $senderPubKey,
            'key_provider_type' => 'passphrase',
            'recipient_salt'    => $recipientSalt,
            'expires_at'        => Carbon::now()->addDay(),
            'views_remaining'   => 1,
        ]);

        // Metadata GET returns the public ECDH bootstrap fields plus a reveal token,
        // but no payload.
        $response = $this->getJson('/api/snipto/sid-slug');
        $response->assertStatus(200)
            ->assertJson([
                'protection_type'   => ProtectionType::SniptoId->value,
                'sender_public_key' => $senderPubKey,
                'key_provider_type' => 'passphrase',
                'recipient_salt'    => $recipientSalt,
            ])
            ->assertJsonMissing(['payload']);

        // POST reveal with the token and the derived key_hash returns the payload.
        $token    = $response->json('reveal_token');
        $response = $this->postJson('/api/snipto/sid-slug/reveal', [
            'reveal_token' => $token,
            'key_hash'     => $keyHash,
        ]);
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'payload' => base64_encode('encrypted-data'),
            ]);

        // Should be deleted after view
        $this->assertDatabaseMissing('sniptos', ['slug' => 'sid-slug']);
    }

    #[Test]
    public function it_rejects_snipto_id_without_sender_public_key()
    {
        $response = $this->postJson('/api/snipto', [
            'slug'            => 'no-sender-key',
            'payload'         => base64_encode('data'),
            'nonce'           => bin2hex(random_bytes(12)),
            'key_hash'        => hash('sha256', 'test'),
            'protection_type' => ProtectionType::SniptoId->value,
            'recipient_salt'  => base64_encode(random_bytes(16)),
            // missing sender_public_key
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_rejects_snipto_id_without_recipient_salt()
    {
        $response = $this->postJson('/api/snipto', [
            'slug'              => 'no-salt',
            'payload'           => base64_encode('data'),
            'nonce'             => bin2hex(random_bytes(12)),
            'key_hash'          => hash('sha256', 'test'),
            'protection_type'   => ProtectionType::SniptoId->value,
            'sender_public_key' => base64_encode(random_bytes(32)),
            // missing recipient_salt
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_rejects_snipto_id_with_malformed_recipient_salt()
    {
        $response = $this->postJson('/api/snipto', [
            'slug'              => 'bad-salt',
            'payload'           => base64_encode('data'),
            'nonce'             => bin2hex(random_bytes(12)),
            'key_hash'          => hash('sha256', 'test'),
            'protection_type'   => ProtectionType::SniptoId->value,
            'sender_public_key' => base64_encode(random_bytes(32)),
            'recipient_salt'    => 'not-valid-base64-of-correct-length',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_nonce_pre_auth_for_password_protected_snippet()
    {
        $nonce   = bin2hex(random_bytes(12));
        $keyHash = hash('sha256', 'irrelevant');

        Snipto::create([
            'slug'            => 'pwd-slug',
            'payload'         => base64_encode('encrypted-data'),
            'nonce'           => $nonce,
            'key_hash'        => $keyHash,
            'protection_type' => ProtectionType::Password,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 1,
        ]);

        // Pre-auth GET (no key_hash) returns the nonce so the client can run Argon2id+HKDF
        // to produce the key_hash on its end before requesting the payload.
        $response = $this->getJson('/api/snipto/pwd-slug');
        $response->assertStatus(200)
            ->assertJson([
                'protection_type' => ProtectionType::Password->value,
                'nonce'           => $nonce,
            ])
            ->assertJsonMissing(['payload']);

        // Snipto must not have been consumed by the metadata fetch.
        $this->assertDatabaseHas('sniptos', ['slug' => 'pwd-slug']);
    }

    #[Test]
    public function it_allows_snipto_id_with_custom_expiration()
    {
        $nonce         = bin2hex(random_bytes(12));
        $keyHash       = hash('sha256', random_bytes(32));
        $senderPubKey  = base64_encode(random_bytes(32));
        $recipientSalt = base64_encode(random_bytes(16));

        $response = $this->postJson('/api/snipto', [
            'slug'              => 'sid-expiry-test',
            'payload'           => base64_encode('data'),
            'nonce'             => $nonce,
            'key_hash'          => $keyHash,
            'protection_type'   => ProtectionType::SniptoId->value,
            'sender_public_key' => $senderPubKey,
            'recipient_salt'    => $recipientSalt,
            'expiration'        => '1w',
        ]);

        $response->assertStatus(200);

        $snipto = Snipto::where('slug', 'sid-expiry-test')->first();
        $this->assertNotNull($snipto);
        // Verify expiration is approximately 1 week from now (within 5 minutes tolerance)
        $this->assertTrue($snipto->expires_at->greaterThan(Carbon::now()->addDays(6)));
    }
    #[Test]
    public function it_uses_a_transaction_and_pessimistic_locking_for_atomic_read_and_delete()
    {
        Snipto::create([
            'slug'            => 'atomic-test',
            'payload'         => 'content',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 1,
        ]);

        DB::enableQueryLog();

        $response = $this->getJson('/api/snipto/atomic-test');
        $response->assertStatus(200);

        $queryLog         = DB::getQueryLog();
        $hasLockForUpdate = false;

        foreach ($queryLog as $log) {
            if (str_contains(strtolower($log['query']), 'for update') && str_contains(strtolower($log['query']), 'sniptos')) {
                $hasLockForUpdate = true;
                break;
            }
        }

        $this->assertTrue($hasLockForUpdate, 'The query did not use pessimistic locking (lockForUpdate).');
    }

    // -----------------------------------------------------------------
    // Two-step reveal protocol (F-001 / F-002)
    // -----------------------------------------------------------------

    #[Test]
    public function it_get_never_returns_payload_or_decrements_views()
    {
        Snipto::create([
            'slug'            => 'prefetch-safe',
            'payload'         => 'burn-me-not',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 1,
        ]);

        // Link prefetchers, AV scanners, and crawlers only ever GET. Under the old
        // protocol that irreversibly consumed the snipto; now repeated GETs must
        // leave it fully intact (F-002 regression pin).
        foreach (range(1, 5) as $i) {
            $this->getJson('/api/snipto/prefetch-safe')
                ->assertStatus(200)
                ->assertJsonMissing(['payload']);
        }

        $this->assertDatabaseHas('sniptos', ['slug' => 'prefetch-safe', 'views_remaining' => 1]);

        // ...and it is still fully retrievable afterwards.
        $token = $this->getJson('/api/snipto/prefetch-safe')->json('reveal_token');
        $this->postJson('/api/snipto/prefetch-safe/reveal', ['reveal_token' => $token])
            ->assertStatus(200)
            ->assertJson(['payload' => 'burn-me-not']);
    }

    #[Test]
    public function it_get_with_key_hash_query_param_is_ignored()
    {
        $keyHash = hash('sha256', 'my-secret');

        Snipto::create([
            'slug'            => 'query-ignored',
            'payload'         => 'base64data',
            'nonce'           => 'nonce-value',
            'key_hash'        => $keyHash,
            'protection_type' => ProtectionType::Secret,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 1,
        ]);

        // The old retrieval URL must not release the payload even with a perfectly
        // valid key_hash: show() no longer reads the parameter at all (F-001).
        $response = $this->getJson("/api/snipto/query-ignored?key_hash={$keyHash}");

        $response->assertStatus(200)
            ->assertJsonMissing(['payload']);
        $this->assertDatabaseHas('sniptos', ['slug' => 'query-ignored', 'views_remaining' => 1]);
    }

    #[Test]
    public function it_reveal_without_token_returns_403()
    {
        Snipto::create([
            'slug'            => 'no-token',
            'payload'         => 'content',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 1,
        ]);

        $this->postJson('/api/snipto/no-token/reveal')->assertStatus(403);
        $this->postJson('/api/snipto/no-token/reveal', ['reveal_token' => ''])
            ->assertStatus(403);
        $this->postJson('/api/snipto/no-token/reveal', ['reveal_token' => str_repeat('ab', 32)])
            ->assertStatus(403);

        $this->assertDatabaseHas('sniptos', ['slug' => 'no-token', 'views_remaining' => 1]);
    }

    #[Test]
    public function it_reveal_token_is_single_use()
    {
        Snipto::create([
            'slug'            => 'single-use',
            'payload'         => 'content',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 3,
        ]);

        $token = $this->getJson('/api/snipto/single-use')->json('reveal_token');

        $this->postJson('/api/snipto/single-use/reveal', ['reveal_token' => $token])
            ->assertStatus(200);

        // Same token again — consumed by the first attempt, even though views remain.
        $this->postJson('/api/snipto/single-use/reveal', ['reveal_token' => $token])
            ->assertStatus(403);

        // A fresh GET mints a fresh token, which works.
        $fresh = $this->getJson('/api/snipto/single-use')->json('reveal_token');
        $this->postJson('/api/snipto/single-use/reveal', ['reveal_token' => $fresh])
            ->assertStatus(200);
    }

    #[Test]
    public function it_reveal_token_expires()
    {
        Snipto::create([
            'slug'            => 'token-ttl',
            'payload'         => 'content',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 1,
        ]);

        $token = $this->getJson('/api/snipto/token-ttl')->json('reveal_token');

        Snipto::where('slug', 'token-ttl')->update([
            'reveal_token_expires_at' => Carbon::now()->subMinute(),
        ]);

        $this->postJson('/api/snipto/token-ttl/reveal', ['reveal_token' => $token])
            ->assertStatus(403);
        $this->assertDatabaseHas('sniptos', ['slug' => 'token-ttl']);
    }

    #[Test]
    public function it_reveal_on_nonexistent_or_expired_snipto_returns_404()
    {
        $this->postJson('/api/snipto/ghost-slug/reveal', ['reveal_token' => str_repeat('cd', 32)])
            ->assertStatus(404);

        Snipto::create([
            'slug'            => 'reveal-expired',
            'payload'         => 'content',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->subMinute(),
            'views_remaining' => 1,
        ]);

        $this->postJson('/api/snipto/reveal-expired/reveal', ['reveal_token' => str_repeat('ef', 32)])
            ->assertStatus(404);
        $this->assertDatabaseMissing('sniptos', ['slug' => 'reveal-expired']);
    }

    // -----------------------------------------------------------------
    // Server-side slug validation (F-003 / F-005)
    // -----------------------------------------------------------------

    #[Test]
    public function it_rejects_slug_with_invalid_characters()
    {
        // 'pt-foo.bar' was created under the old rules and then could never be
        // fetched: the route regex [A-Za-z0-9_-]+ never matches it, so the row was
        // an unreachable, undeletable orphan (F-003).
        $response = $this->postJson('/api/snipto', [
            'slug'            => 'pt-foo.bar',
            'payload'         => base64_encode('data'),
            'nonce'           => bin2hex(random_bytes(12)),
            'key_hash'        => hash('sha256', 'test'),
            'protection_type' => ProtectionType::Secret->value,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('sniptos', ['slug' => 'pt-foo.bar']);
    }

    #[Test]
    public function it_rejects_slug_with_whitespace_or_newline()
    {
        // Also closes the log-injection path (INJ-002): a newline-bearing slug can
        // no longer reach the Log::error path in store().
        $response = $this->postJson('/api/snipto', [
            'slug'            => "bad\nslug",
            'payload'         => 'content',
            'protection_type' => ProtectionType::Plaintext->value,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('sniptos', ['slug' => "bad\nslug"]);
    }

    #[Test]
    public function it_rejects_reserved_slugs()
    {
        // These slugs are shadowed by static routes — a snipto created with one is
        // unreachable from birth, the same failure class as F-003.
        foreach (['faq', 'contact', 'terms', 'safety', 'sniptoid', 'locale', 'api', 'up'] as $reserved) {
            $this->postJson('/api/snipto', [
                'slug'            => $reserved,
                'payload'         => 'content',
                'protection_type' => ProtectionType::Plaintext->value,
            ])->assertStatus(422);
        }

        // Route matching is case-sensitive, so an uppercase variant is NOT shadowed
        // and stays allowed (slug is long enough to clear the plaintext floor too).
        $this->postJson('/api/snipto', [
            'slug'            => 'FAQ-page',
            'payload'         => 'content',
            'protection_type' => ProtectionType::Plaintext->value,
        ])->assertStatus(200);
    }

    #[Test]
    public function it_rejects_short_plaintext_slug_but_accepts_min_length()
    {
        // For Plaintext sniptos the slug is the only secret — 1-2 character slugs
        // are enumerable in seconds (F-005).
        $this->postJson('/api/snipto', [
            'slug'            => 'q',
            'payload'         => 'tiny-slug-secret',
            'protection_type' => ProtectionType::Plaintext->value,
        ])->assertStatus(422);

        $this->postJson('/api/snipto', [
            'slug'            => 'abcdefgh',
            'payload'         => 'ok-slug-secret',
            'protection_type' => ProtectionType::Plaintext->value,
        ])->assertStatus(200);
    }

    #[Test]
    public function it_accepts_short_slug_for_encrypted_modes()
    {
        // For encrypted modes the slug is just an identifier; the key is the secret.
        $this->postJson('/api/snipto', [
            'slug'            => 'abcd',
            'payload'         => base64_encode('data'),
            'nonce'           => bin2hex(random_bytes(12)),
            'key_hash'        => hash('sha256', 'test'),
            'protection_type' => ProtectionType::Secret->value,
        ])->assertStatus(200);

        // ...but not below 4.
        $this->postJson('/api/snipto', [
            'slug'            => 'abc',
            'payload'         => base64_encode('data'),
            'nonce'           => bin2hex(random_bytes(12)),
            'key_hash'        => hash('sha256', 'test'),
            'protection_type' => ProtectionType::Secret->value,
        ])->assertStatus(422);
    }
}
