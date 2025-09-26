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
            $table->string('key_hash', 64)->after('payload');
            $table->string('plaintext_hmac', 64)->after('key_hash');
            $table->string('nonce', 24)->after('plaintext_hmac');
            $table->dropColumn('iv');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->dropColumn('key_hash');
            $table->dropColumn('plaintext_hmac');
            $table->dropColumn('nonce');
            $table->string('iv', 255)->after('payload');
        });
    }
};
