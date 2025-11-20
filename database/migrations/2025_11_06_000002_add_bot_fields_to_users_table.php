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
        Schema::table('users', function (Blueprint $table) {
            // Bot identification and control
            $table->boolean('is_bot')->default(false);
            $table->boolean('bot_enabled')->default(true);
            
            // Bot configuration
            $table->integer('bot_skill_level')->default(5);
            $table->string('bot_strategy', 50)->default('balanced');
            $table->unsignedBigInteger('bot_ai_config_id')->nullable();
            $table->string('bot_ai_model', 100)->nullable();
            
            // Backup AI configuration
            $table->unsignedBigInteger('backup_bot_ai_config_id')->nullable();
            $table->string('backup_bot_ai_model', 100)->nullable();
            
            // Bot state tracking
            $table->timestamp('bot_last_action')->nullable();
            $table->timestamp('bot_processing_until')->nullable();
            $table->timestamp('bot_last_heartbeat')->nullable();
            $table->text('bot_notes')->nullable();

            // Indexes for bot queries
            $table->index(['is_bot', 'bot_enabled', 'bot_last_action']);
            $table->index('bot_processing_until');
            $table->index('bot_ai_config_id');
            $table->index('backup_bot_ai_config_id');

            // Foreign keys
            $table->foreign('bot_ai_config_id')
                  ->references('id')
                  ->on('bot_ai_configs')
                  ->onDelete('set null');
                  
            $table->foreign('backup_bot_ai_config_id')
                  ->references('id')
                  ->on('bot_ai_configs')
                  ->onDelete('set null');
        });

        // Skill level constraint (1-10) - added after table modification
        DB::statement('ALTER TABLE users ADD CONSTRAINT check_bot_skill_level CHECK (bot_skill_level BETWEEN 1 AND 10)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop foreign keys and indexes first
            $table->dropForeign(['bot_ai_config_id']);
            $table->dropForeign(['backup_bot_ai_config_id']);
            $table->dropIndex(['is_bot', 'bot_enabled', 'bot_last_action']);
            $table->dropIndex(['bot_processing_until']);
            $table->dropIndex(['bot_ai_config_id']);
            $table->dropIndex(['backup_bot_ai_config_id']);

            // Drop columns
            $table->dropColumn([
                'is_bot',
                'bot_enabled',
                'bot_skill_level',
                'bot_strategy',
                'bot_ai_config_id',
                'bot_ai_model',
                'backup_bot_ai_config_id',
                'backup_bot_ai_model',
                'bot_last_action',
                'bot_processing_until',
                'bot_last_heartbeat',
                'bot_notes',
            ]);
        });

        // Drop constraint
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS check_bot_skill_level');
    }
};
