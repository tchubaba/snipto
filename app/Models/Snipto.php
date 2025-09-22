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
 * @property string $iv
 * @property int|null $views_remaining
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|Snipto whereId($value)
 * @method static Builder|Snipto whereSlug($value)
 * @method static Builder|Snipto wherePayload($value)
 * @method static Builder|Snipto whereIv($value)
 * @method static Builder|Snipto whereViewsRemaining($value)
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
        'iv',
        'views_remaining',
        'expires_at',
    ];

    protected $casts = [
        'slug'            => 'string',
        'payload'         => 'string',
        'iv'              => 'string',
        'views_remaining' => 'integer',
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
    public function decrementViews(): void
    {
        // Unlimited views
        if ($this->views_remaining === null) {
            return;
        }

        $this->decrement('views_remaining');
        if ($this->views_remaining < 1) {
            $this->delete();
        }
    }

    /**
     * SHA256 hashes the payload and returns it.
     */
    public function getPayloadHash(): string
    {
        return hash('sha256', $this->payload);
    }
}
