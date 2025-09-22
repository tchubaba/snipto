<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sniptos', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->longText('payload');
            $table->string('iv');
            $table->smallInteger('views_remaining')->default(1)->nullable()->unsigned();
            $table->dateTime('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sniptos');
    }
};
