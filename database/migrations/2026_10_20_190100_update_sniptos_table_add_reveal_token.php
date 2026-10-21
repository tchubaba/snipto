<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reveal-token columns backing the two-step retrieval protocol (pentest findings
 * F-001 and F-002). GET /api/snipto/{slug} is now metadata-only and mints a
 * one-time reveal token; the payload is released exclusively by
 * POST /api/snipto/{slug}/reveal, which takes the token and key_hash in the POST
 * body. Only the SHA-256 of the token is stored — a leaked database never yields
 * live reveal capabilities.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->string('reveal_token_hash', 64)->nullable()->after('views_remaining');
            $table->dateTime('reveal_token_expires_at')->nullable()->after('reveal_token_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->dropColumn(['reveal_token_hash', 'reveal_token_expires_at']);
        });
    }
};
