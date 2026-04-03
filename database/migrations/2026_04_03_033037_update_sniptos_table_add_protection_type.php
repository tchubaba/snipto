<?php

use App\Enums\ProtectionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->unsignedTinyInteger('protection_type')->default(ProtectionType::Secret->value)->after('payload');
        });

        // Migrate existing data
        DB::table('sniptos')->where('is_encrypted', false)->update(['protection_type' => ProtectionType::Plaintext->value]);
        DB::table('sniptos')->where('is_encrypted', true)->update(['protection_type' => ProtectionType::Secret->value]);

        Schema::table('sniptos', function (Blueprint $table) {
            $table->dropColumn('is_encrypted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sniptos', function (Blueprint $table) {
            $table->boolean('is_encrypted')->default(true)->after('payload');
        });

        // Reverse data migration
        DB::table('sniptos')->where('protection_type', ProtectionType::Plaintext->value)->update(['is_encrypted' => false]);
        DB::table('sniptos')->whereIn('protection_type', [ProtectionType::Secret->value, ProtectionType::Password->value])->update(['is_encrypted' => true]);

        Schema::table('sniptos', function (Blueprint $table) {
            $table->dropColumn('protection_type');
        });
    }
};
