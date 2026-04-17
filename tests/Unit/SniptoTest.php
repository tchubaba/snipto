<?php

namespace Tests\Unit;

use App\Enums\ProtectionType;
use App\Models\Snipto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SniptoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_check_if_it_has_expired()
    {
        $expiredSnipto = new Snipto([
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $activeSnipto = new Snipto([
            'expires_at' => Carbon::now()->addMinute(),
        ]);

        $this->assertTrue($expiredSnipto->isExpired());
        $this->assertFalse($activeSnipto->isExpired());
    }

    #[Test]
    public function it_can_decrement_views_and_delete_when_reaching_zero()
    {
        $snipto = Snipto::create([
            'slug'            => 'test-slug',
            'payload'         => 'some data',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 1,
        ]);

        $remaining = $snipto->decrementViews();

        $this->assertEquals(0, $remaining);
        $this->assertDatabaseMissing('sniptos', ['slug' => 'test-slug']);
    }

    #[Test]
    public function it_does_not_delete_when_views_remaining_is_above_zero()
    {
        $snipto = Snipto::create([
            'slug'            => 'test-slug',
            'payload'         => 'some data',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => 2,
        ]);

        $remaining = $snipto->decrementViews();

        $this->assertEquals(1, $remaining);
        $this->assertDatabaseHas('sniptos', ['slug' => 'test-slug']);
    }

    #[Test]
    public function it_handles_unlimited_views()
    {
        $snipto = Snipto::create([
            'slug'            => 'test-slug',
            'payload'         => 'some data',
            'protection_type' => ProtectionType::Plaintext,
            'expires_at'      => Carbon::now()->addHour(),
            'views_remaining' => null,
        ]);

        $remaining = $snipto->decrementViews();

        $this->assertNull($remaining);
        $this->assertDatabaseHas('sniptos', ['slug' => 'test-slug']);
    }

    #[Test]
    public function it_knows_if_it_is_encrypted()
    {
        $plaintext = new Snipto(['protection_type' => ProtectionType::Plaintext]);
        $secret    = new Snipto(['protection_type' => ProtectionType::Secret]);
        $password  = new Snipto(['protection_type' => ProtectionType::Password]);
        $sniptoId  = new Snipto(['protection_type' => ProtectionType::SniptoId]);

        $this->assertFalse($plaintext->isEncrypted());
        $this->assertTrue($secret->isEncrypted());
        $this->assertTrue($password->isEncrypted());
        $this->assertTrue($sniptoId->isEncrypted());
    }

    #[Test]
    public function it_knows_if_it_is_password_protected()
    {
        $plaintext = new Snipto(['protection_type' => ProtectionType::Plaintext]);
        $secret    = new Snipto(['protection_type' => ProtectionType::Secret]);
        $password  = new Snipto(['protection_type' => ProtectionType::Password]);

        $this->assertFalse($plaintext->isPasswordProtected());
        $this->assertFalse($secret->isPasswordProtected());
        $this->assertTrue($password->isPasswordProtected());
    }

    #[Test]
    public function it_knows_if_it_is_snipto_id()
    {
        $plaintext = new Snipto(['protection_type' => ProtectionType::Plaintext]);
        $secret    = new Snipto(['protection_type' => ProtectionType::Secret]);
        $password  = new Snipto(['protection_type' => ProtectionType::Password]);
        $sniptoId  = new Snipto(['protection_type' => ProtectionType::SniptoId]);

        $this->assertFalse($plaintext->isSniptoId());
        $this->assertFalse($secret->isSniptoId());
        $this->assertFalse($password->isSniptoId());
        $this->assertTrue($sniptoId->isSniptoId());
    }

    #[Test]
    public function snipto_id_type_is_encrypted()
    {
        $sniptoId = new Snipto(['protection_type' => ProtectionType::SniptoId]);
        $this->assertTrue($sniptoId->isEncrypted());
    }
}
