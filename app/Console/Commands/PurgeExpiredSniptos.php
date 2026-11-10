<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Snipto;
use Illuminate\Console\Command;

/**
 * Deletes snipto rows whose expiration has passed (audit finding F-3), and
 * file-mode rows whose upload never completed.
 *
 * Expiry is enforced at read time (isExpired() / route probing), so an expired
 * row is never servable — but it lingers in the database until something
 * deletes it. Plaintext-mode payloads in particular keep their payload at rest
 * past their nominal expiry until purged. Abandoned file uploads are reaped
 * after 30 minutes: the one-time upload token itself dies at 30 minutes, so a
 * file row older than that with no blob can never complete. bootstrap/app.php
 * registers this command on the Laravel scheduler (every 15 minutes), and the
 * shipped Docker entrypoints start `schedule:work` so it fires automatically
 * in every containerized mode; bare-metal deployments must tick the schedule
 * themselves (`schedule:run` via crontab) or run this command directly.
 */
class PurgeExpiredSniptos extends Command
{
    protected $signature = 'sniptos:purge';

    protected $description = 'Delete sniptos whose expiration has passed (storage hygiene)';

    /**
     * Chunked delete: keeps each statement short enough to avoid long row
     * locks on MariaDB when the backlog is large (e.g. first run after
     * deploying this command).
     */
    private const CHUNK = 500;

    /**
     * Grace period for file-mode rows without a blob: matches the 30-minute
     * upload-token TTL in store(). A row younger than this may have a PUT
     * genuinely in flight and must survive.
     */
    private const ABANDONED_UPLOAD_MINUTES = 30;

    public function handle(): int
    {
        $total = 0;

        do {
            $ids = Snipto::query()
                ->where(function ($q) {
                    $q->where('expires_at', '<=', now());
                })
                ->orWhere(function ($q) {
                    // Abandoned file uploads: a row whose PUT never landed and
                    // whose upload token has expired (or has no active token
                    // and is older than the 30-minute grace window).
                    $q->where('kind', 'file')
                      ->whereNull('payload_blob')
                      ->where(function ($inner) {
                          $inner->where('upload_token_expires_at', '<=', now())
                              ->orWhere(function ($fallback) {
                                  $fallback->whereNull('upload_token_expires_at')
                                      ->where('created_at', '<', now()->subMinutes(self::ABANDONED_UPLOAD_MINUTES));
                              });
                      });
                })
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += Snipto::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        $this->info("Purged {$total} expired snipto(s).");

        return self::SUCCESS;
    }
}
