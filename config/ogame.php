<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bot System Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the AI-driven bot system that manages automated
    | player accounts with different strategies and behaviors.
    |
    */

    'bots' => [
        'enabled' => env('BOTS_ENABLED', true),
        'max_bots' => env('MAX_BOTS', 100),
        'action_interval_minutes' => env('BOT_ACTION_INTERVAL', 5),
        'lock_duration_seconds' => env('BOT_LOCK_DURATION', 60),
        'max_attacks_per_hour' => env('BOT_MAX_ATTACKS_PER_HOUR', 1),

        /*
        |--------------------------------------------------------------------------
        | AI Provider Settings
        |--------------------------------------------------------------------------
        |
        | Configuration for AI API calls.
        | Note: URL, model, and API keys are stored in bot_ai_configs table.
        |
        */

        'ai' => [
            'timeout_seconds' => env('BOT_AI_TIMEOUT', 30),
            'max_retries' => env('BOT_AI_MAX_RETRIES', 1),
        ],

        /*
        |--------------------------------------------------------------------------
        | Quota Management
        |--------------------------------------------------------------------------
        |
        | Per-bot API request rate limiting.
        | Prevents a single bot from making too many API calls per hour.
        |
        */

        'quotas' => [
            // Per-bot limits (requests only)
            'requests_per_bot_hourly' => env('BOT_REQUESTS_PER_BOT_HOURLY', 12),
        ],

        /*
        |--------------------------------------------------------------------------
        | Logging Configuration
        |--------------------------------------------------------------------------
        |
        | Security-focused logging settings to protect API keys and sensitive data.
        |
        */

        'logging' => [
            'enabled' => true,
            'level' => 'info',
            'max_request_size_bytes' => 5000,
            'max_response_size_bytes' => 5000,
            'redact_api_keys' => true,
        ],
    ],
];
