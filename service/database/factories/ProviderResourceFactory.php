<?php

namespace Database\Factories;

use App\AvatarProviders\ResourceKind;
use App\Models\ProviderResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderResource>
 */
class ProviderResourceFactory extends Factory
{
    protected $model = ProviderResource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'heygen',
            'key_fingerprint' => substr(hash('sha256', fake()->uuid()), 0, 16),
            'kind' => ResourceKind::Voice,
            'natural_key' => fake()->unique()->slug(3),
            'external_id' => fake()->uuid(),
        ];
    }
}
