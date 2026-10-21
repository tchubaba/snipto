<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time purge of snipto rows whose slug can never match the retrieval route
 * regex ([A-Za-z0-9_-]+ in routes/web.php). Such rows are unreachable, cannot be
 * viewed or consumed, and are invisible to the lazy-expiry path (deletion happens
 * inside show(), which the route never reaches for them) — permanent orphans and a
 * storage-growth vector (pentest finding F-003).
 *
 * Creation of such slugs is now rejected server-side (ApiController::store()); this
 * migration removes the ones that predate the fix. This is a one-time cleanup, not
 * a scheduled sweep — recurring expiry sweeping is tracked separately (F-004).
 *
 * The scan is done in PHP rather than SQL REGEXP so it behaves identically on
 * MariaDB (production) and SQLite (tests).
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $invalidIds = [];

        foreach (DB::table('sniptos')->cursor(['id', 'slug']) as $row) {
            if (preg_match('/^[A-Za-z0-9_-]+$/', $row->slug) !== 1) {
                $invalidIds[] = $row->id;
            }
        }

        if ($invalidIds !== []) {
            DB::table('sniptos')->whereIn('id', $invalidIds)->delete();
        }
    }

    /**
     * Reverse the migrations. Deleted rows are user data past its intended
     * lifetime; the purge is intentionally not reversible.
     */
    public function down(): void
    {
        //
    }
};
