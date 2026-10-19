<?php

namespace Database\Factories;

use App\Credentials\LastFour;
use App\Models\ProviderCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderCredential>
 */
class ProviderCredentialFactory extends Factory
{
    protected $model = ProviderCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'key_'.fake()->unique()->bothify('????????########');

        return ['provider' => 'tavus', 'api_key' => $key, 'last_four' => LastFour::of($key)];
    }
}
