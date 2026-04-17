<?php

namespace App\Models;

use App\Enums\ProtectionType;
use Carbon\Carbon;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Snipto
 *
 * @property int $id
 * @property string $slug
 * @property string $payload
 * @property string|null $key_hash
 * @property string|null $nonce
 * @property string|null $sender_public_key
 * @property string|null $key_provider_type
 * @property int|null $views_remaining
 * @property ProtectionType $protection_type
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|Snipto whereId($value)
 * @method static Builder|Snipto whereSlug($value)
 * @method static Builder|Snipto wherePayload($value)
 * @method static Builder|Snipto whereKeyHash($value)
 * @method static Builder|Snipto whereNonce($value)
 * @method static Builder|Snipto whereViewsRemaining($value)
 * @method static Builder|Snipto whereProtectionType($value)
 * @method static Builder|Snipto whereExpiresAt($value)
 * @method static Builder|Snipto whereCreatedAt($value)
 * @method static Builder|Snipto whereUpdatedAt($value)
 * @method static Snipto create(array $attributes = [])
 *
 * @mixin Eloquent
 */
class Snipto extends Model
{
    protected $table = 'sniptos';

    protected $fillable = [
        'slug',
        'payload',
        'key_hash',
        'nonce',
        'sender_public_key',
        'key_provider_type',
        'views_remaining',
        'protection_type',
        'expires_at',
    ];

    protected $casts = [
        'slug'              => 'string',
        'payload'           => 'string',
        'key_hash'          => 'string',
        'nonce'             => 'string',
        'sender_public_key' => 'string',
        'key_provider_type' => 'string',
        'views_remaining'   => 'integer',
        'protection_type'   => ProtectionType::class,
        'expires_at'        => 'datetime',
    ];

    /**
     * Check if this Snipto has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at <= now();
    }

    /**
     * Decrement views remaining and delete if needed.
     */
    public function decrementViews(): ?int
    {
        // Unlimited views
        if ($this->views_remaining === null) {
            return null;
        }

        $this->decrement('views_remaining');
        $viewRemaining = $this->views_remaining;
        if ($this->views_remaining < 1) {
            $this->delete();
        }

        return $viewRemaining;
    }

    /**
     * Indicates whether the Snipto payload is encrypted.
     */
    public function isEncrypted(): bool
    {
        return $this->protection_type !== ProtectionType::Plaintext;
    }

    /**
     * Indicates whether the Snipto is protected by a password.
     */
    public function isPasswordProtected(): bool
    {
        return $this->protection_type === ProtectionType::Password;
    }

    /**
     * Indicates whether the Snipto uses Snipto ID (asymmetric) encryption.
     */
    public function isSniptoId(): bool
    {
        return $this->protection_type === ProtectionType::SniptoId;
    }
}
