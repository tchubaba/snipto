<?php

namespace App\Models;

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
 * @property int|null $views_remaining
 * @property boolean $is_encrypted
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
 * @method static Builder|Snipto whereIsEncrypted($value)
 * @method static Builder|Snipto whereExpiresAt($value)
 * @method static Builder|Snipto whereCreatedAt($value)
 * @method static Builder|Snipto whereUpdatedAt($value)
 *
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
        'views_remaining',
        'is_encrypted',
        'expires_at',
    ];

    protected $casts = [
        'slug'            => 'string',
        'payload'         => 'string',
        'key_hash'        => 'string',
        'nonce'           => 'string',
        'views_remaining' => 'integer',
        'is_encrypted'    => 'boolean',
        'expires_at'      => 'datetime',
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
        return $this->is_encrypted;
    }
}
