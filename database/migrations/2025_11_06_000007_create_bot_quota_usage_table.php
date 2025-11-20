<?php

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
        Schema::create('bot_quota_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bot_id'); // 0 = global tracker
            $table->dateTime('hour'); // YYYY-MM-DD HH:00:00
            $table->integer('requests_used')->default(0);
            $table->timestamps();

            // Unique constraint for atomic upserts
            $table->unique(['bot_id', 'hour']);

            // Index for bot_id lookups
            $table->index('bot_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_quota_usage');
    }
};
