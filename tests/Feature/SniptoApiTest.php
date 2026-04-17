<?php

namespace Tests\Feature;

use App\Enums\ProtectionType;
use App\Models\Snipto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

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

        $response = $this->getJson('/api/snipto/my-slug');

        $response->assertStatus(200)
            ->assertJson([
                'success'         => true,
                'payload'         => 'Secret Content',
                'protection_type' => ProtectionType::Plaintext->value,
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

        $response = $this->getJson("/api/snipto/enc-slug?key_hash={$keyHash}");

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

        $response = $this->getJson("/api/snipto/enc-slug?key_hash={$wrongHash}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('sniptos', ['slug' => 'enc-slug']);
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
        $nonce        = bin2hex(random_bytes(12));
        $keyHash      = hash('sha256', random_bytes(32));
        $senderPubKey = base64_encode(random_bytes(32)); // 44 chars

        $response = $this->postJson('/api/snipto', [
            'slug'              => 'test-snipto-id',
            'payload'           => base64_encode('encrypted-data'),
            'nonce'             => $nonce,
            'key_hash'          => $keyHash,
            'protection_type'   => ProtectionType::SniptoId->value,
            'sender_public_key' => $senderPubKey,
            'key_provider_type' => 'passphrase',
            'expiration'        => '1d',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'slug' => 'test-snipto-id']);

        $this->assertDatabaseHas('sniptos', [
            'slug'              => 'test-snipto-id',
            'protection_type'   => ProtectionType::SniptoId->value,
            'sender_public_key' => $senderPubKey,
            'key_provider_type' => 'passphrase',
        ]);
    }

    #[Test]
    public function it_returns_sender_public_key_for_snipto_id_type()
    {
        $senderPubKey = base64_encode(random_bytes(32));
        $keyHash      = hash('sha256', 'recipient-pub');

        Snipto::create([
            'slug'              => 'sid-slug',
            'payload'           => base64_encode('encrypted-data'),
            'nonce'             => bin2hex(random_bytes(12)),
            'key_hash'          => $keyHash,
            'protection_type'   => ProtectionType::SniptoId,
            'sender_public_key' => $senderPubKey,
            'key_provider_type' => 'passphrase',
            'expires_at'        => Carbon::now()->addDay(),
            'views_remaining'   => 1,
        ]);

        // First call without key_hash returns metadata but no payload
        $response = $this->getJson('/api/snipto/sid-slug');
        $response->assertStatus(200)
            ->assertJson([
                'protection_type'   => ProtectionType::SniptoId->value,
                'sender_public_key' => $senderPubKey,
                'key_provider_type' => 'passphrase',
            ])
            ->assertJsonMissing(['payload']);

        // Second call with correct key_hash returns payload
        $response = $this->getJson("/api/snipto/sid-slug?key_hash={$keyHash}");
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
            // missing sender_public_key
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_allows_snipto_id_with_custom_expiration()
    {
        $nonce        = bin2hex(random_bytes(12));
        $keyHash      = hash('sha256', random_bytes(32));
        $senderPubKey = base64_encode(random_bytes(32));

        $response = $this->postJson('/api/snipto', [
            'slug'              => 'sid-expiry-test',
            'payload'           => base64_encode('data'),
            'nonce'             => $nonce,
            'key_hash'          => $keyHash,
            'protection_type'   => ProtectionType::SniptoId->value,
            'sender_public_key' => $senderPubKey,
            'expiration'        => '1w',
        ]);

        $response->assertStatus(200);

        $snipto = Snipto::where('slug', 'sid-expiry-test')->first();
        $this->assertNotNull($snipto);
        // Verify expiration is approximately 1 week from now (within 5 minutes tolerance)
        $this->assertTrue($snipto->expires_at->greaterThan(Carbon::now()->addDays(6)));
    }
}
