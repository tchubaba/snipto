<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->string('sender_public_key', 44)->nullable()->after('nonce');
            $table->string('key_provider_type', 20)->nullable()->after('sender_public_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->dropColumn(['sender_public_key', 'key_provider_type']);
        });
    }
};
