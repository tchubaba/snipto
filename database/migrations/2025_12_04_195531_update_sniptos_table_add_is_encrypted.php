<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class () extends Migration {
    protected string $table = 'sniptos';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table($this->table, function (Blueprint $table) {
            $table->boolean('is_encrypted')->default(true)->after('views_remaining');
            $table->string('key_hash')->nullable()->change();
            $table->string('nonce')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table($this->table, function (Blueprint $table) {
            $table->dropColumn('is_encrypted');
            $table->string('key_hash')->nullable(false)->change();
            $table->string('nonce')->nullable(false)->change();
        });
    }
};
