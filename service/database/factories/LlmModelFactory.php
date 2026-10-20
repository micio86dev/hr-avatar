<?php

namespace Database\Factories;

use App\Models\LlmModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LlmModel>
 */
class LlmModelFactory extends Factory
{
    protected $model = LlmModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->unique()->slug(2),
            'provider' => 'gemini',
            'model' => fake()->unique()->slug(3),
            'display_name' => fake()->words(3, true),
            'base_url' => 'https://llm.example.test/v1/',
            'enabled' => true,
        ];
    }
}
