<?php

namespace Database\Factories;

use App\Credentials\LastFour;
use App\Models\LlmCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LlmCredential>
 */
class LlmCredentialFactory extends Factory
{
    protected $model = LlmCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'key_'.fake()->unique()->bothify('????????########');

        return ['llm_provider' => 'gemini', 'api_key' => $key, 'last_four' => LastFour::of($key)];
    }
}
