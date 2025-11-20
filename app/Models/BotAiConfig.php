<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bot AI Configuration Model
 *
 * Stores AI provider configurations that can be shared by multiple bots.
 * API keys are automatically encrypted at rest using Laravel's encrypted cast.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $bot_ai_url
 * @property string $bot_ai_model
 * @property string $bot_ai_api_key - Auto-encrypted/decrypted by Laravel
 * @property bool $is_active
 * @property int $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class BotAiConfig extends Model
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Database\Factories\BotAiConfigFactory::new();
    }

    protected $table = 'bot_ai_configs';

    protected $fillable = [
        'name',
        'description',
        'bot_ai_url',
        'bot_ai_model',
        'bot_ai_api_key',
        'is_active',
        'created_by',
    ];

    /**
     * CRITICAL: Automatic encryption/decryption of API keys
     * - On save: Laravel encrypts the value using APP_KEY before storing in DB
     * - On retrieve: Laravel automatically decrypts using APP_KEY
     * - Database stores encrypted blob, never plain text
     */
    protected $casts = [
        'bot_ai_model' => 'array',
        'bot_ai_api_key' => 'encrypted',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the admin user who created this configuration
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all bots using this configuration
     */
    public function bots(): HasMany
    {
        return $this->hasMany(User::class, 'bot_ai_config_id');
    }
}
