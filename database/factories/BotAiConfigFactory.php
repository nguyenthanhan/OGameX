<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use OGame\Models\BotAiConfig;
use OGame\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\OGame\Models\BotAiConfig>
 */
class BotAiConfigFactory extends Factory
{
    protected $model = BotAiConfig::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true) . ' AI Provider',
            'description' => $this->faker->sentence(),
            'bot_ai_url' => $this->faker->randomElement([
                'https://api.openai.com/v1',
                'https://api.groq.com/openai/v1',
                'https://generativelanguage.googleapis.com',
            ]),
            'bot_ai_model' => $this->faker->randomElement([
                ['gpt-4o-mini'],
                ['llama3-70b-8192'],
                ['gemini-1.5-flash'],
                ['gpt-4o-mini', 'gpt-3.5-turbo'],
            ]),
            'bot_ai_api_key' => 'test-api-key-' . $this->faker->uuid(),
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Indicate that the config is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the config uses OpenAI.
     */
    public function openai(): static
    {
        return $this->state(fn (array $attributes) => [
            'bot_ai_url' => 'https://api.openai.com/v1',
            'bot_ai_model' => ['gpt-4o-mini'],
        ]);
    }

    /**
     * Indicate that the config uses Groq.
     */
    public function groq(): static
    {
        return $this->state(fn (array $attributes) => [
            'bot_ai_url' => 'https://api.groq.com/openai/v1',
            'bot_ai_model' => ['llama3-70b-8192'],
        ]);
    }

    /**
     * Indicate that the config uses Gemini.
     */
    public function gemini(): static
    {
        return $this->state(fn (array $attributes) => [
            'bot_ai_url' => 'https://generativelanguage.googleapis.com',
            'bot_ai_model' => ['gemini-1.5-flash'],
        ]);
    }
}

