<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Snipto;
use Illuminate\Console\Command;

/**
 * Deletes snipto rows whose expiration has passed (audit finding F-3).
 *
 * Expiry is enforced at read time (isExpired() / route probing), so an expired
 * row is never servable — but it lingers in the database until something
 * deletes it. Plaintext-mode rows in particular keep their payload at rest
 * past their nominal expiry until purged. bootstrap/app.php registers this
 * command on the Laravel scheduler (every 15 minutes), and the shipped
 * Docker entrypoints start `schedule:work` so it fires automatically in
 * every containerized mode; bare-metal deployments must tick the schedule
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

    public function handle(): int
    {
        $total = 0;

        do {
            $ids = Snipto::query()
                ->where('expires_at', '<=', now())
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
