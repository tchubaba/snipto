<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->dropColumn('plaintext_hmac');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->string('plaintext_hmac', 64)->after('key_hash');
        });
    }
};
