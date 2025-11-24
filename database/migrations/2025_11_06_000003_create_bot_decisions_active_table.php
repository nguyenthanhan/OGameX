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
        Schema::create('bot_decisions_active', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->uuid('turn_id');
            $table->string('idempotency_key', 64)->unique();
            $table->string('action_hash', 64);
            
            // Action details
            $table->string('action_type', 50);
            $table->unsignedInteger('planet_id')->nullable();
            $table->string('target', 255)->nullable();
            $table->integer('quantity')->nullable();
            $table->string('mission_type', 20)->nullable();
            $table->string('destination', 20)->nullable();
            $table->json('ships')->nullable();
            
            // AI metadata
            $table->text('overall_strategy')->nullable();
            
            // Execution results
            $table->string('result', 10);
            $table->text('error_message')->nullable();
            
            $table->timestamps();

            // Indexes for fast queries (HOT table)
            $table->index(['user_id', 'created_at']);
            $table->index('turn_id');
            $table->index(['action_type', 'result']);
            $table->index(['user_id', 'action_type', 'created_at']);

            // Foreign key
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_decisions_active');
    }
};
