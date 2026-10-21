<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Close the latent "NULL views_remaining means unlimited views" behavior
 * (pentest finding AUTHZ-007). The API has always forced views_remaining = 1
 * (ApiController::store()), so any NULL in the column is legacy cruft that would
 * silently grant a snipto infinite retrievals. Backfill the stragglers to the
 * single-view default and make the column NOT NULL so the unlimited state can
 * never be represented at the storage layer.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('sniptos')->whereNull('views_remaining')->update(['views_remaining' => 1]);

        Schema::table('sniptos', function (Blueprint $table) {
            $table->smallInteger('views_remaining')->unsigned()->default(1)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->smallInteger('views_remaining')->unsigned()->nullable()->default(1)->change();
        });
    }
};
