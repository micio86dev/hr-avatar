<?php

namespace Database\Factories;

use App\Models\AvatarConfig;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * A `created` conversation on a new Mock config of the bound account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'avatar_config_id' => AvatarConfig::factory(),
            'provider' => 'mock',
            'language' => 'en',
            'system_prompt' => fake()->sentence(),
            'max_duration_seconds' => 1800,
        ];
    }
}
