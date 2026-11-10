<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * File mode: an opaque binary payload beside the text one, plus the
     * one-time upload token that gates the PUT (F-001 hardening lineage).
     * `payload` becomes nullable — file rows keep it NULL and use
     * `payload_blob`; the text path never touches the blob column.
     */
    public function up(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->string('kind', 8)->default('text')->after('slug');
            $table->longText('payload')->nullable()->change();
            $table->string('upload_token_hash', 64)->nullable()->after('reveal_token_expires_at');
            $table->dateTime('upload_token_expires_at')->nullable()->after('upload_token_hash');
        });

        // A 50 MiB payload needs MariaDB's LONGBLOB; Laravel 13's Blueprint
        // exposes only `binary()` (-> 64 KiB `blob`), so the column is added
        // with raw DDL. SQLite (tests) gets a plain BLOB — no affinity loss,
        // and SQLite's ALTER TABLE has no AFTER clause.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE "sniptos" ADD "payload_blob" blob NULL');
        } else {
            DB::statement('ALTER TABLE `sniptos` ADD `payload_blob` LONGBLOB NULL AFTER `payload`');
        }
    }

    public function down(): void
    {
        // Re-adding the NOT NULL constraint needs non-null rows.
        DB::table('sniptos')->whereNull('payload')->update(['payload' => '']);

        Schema::table('sniptos', function (Blueprint $table) {
            $table->dropColumn(['kind', 'payload_blob', 'upload_token_hash', 'upload_token_expires_at']);
            $table->longText('payload')->nullable(false)->change();
        });
    }
};
