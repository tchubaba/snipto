<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->string('recipient_salt', 24)->nullable()->after('key_provider_type');
        });
    }

    public function down(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->dropColumn('recipient_salt');
        });
    }
};
