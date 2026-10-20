<?php

namespace Database\Factories;

use App\AvatarProviders\SyncStatus;
use App\Models\AvatarConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvatarConfig>
 */
class AvatarConfigFactory extends Factory
{
    protected $model = AvatarConfig::class;

    /**
     * A Mock config: it needs no credential and no sync.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'provider' => 'mock',
            'config' => [],
            'sync_status' => SyncStatus::NotRequired,
        ];
    }
}
