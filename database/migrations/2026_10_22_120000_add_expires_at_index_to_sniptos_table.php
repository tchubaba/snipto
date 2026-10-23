<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Supports the sniptos:purge command and ApiController's opportunistic
        // expired-row sweep (audit finding F-3) without full-table scans.
        Schema::table('sniptos', function (Blueprint $table): void {
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('sniptos', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
        });
    }
};
