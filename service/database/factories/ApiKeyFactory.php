<?php

namespace Database\Factories;

use App\Keys\KeyGenerator;
use App\Keys\KeyMode;
use App\Keys\KeyType;
use App\Keys\Scope;
use App\Models\ApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * A secret live key whose raw value nobody holds. Use withRawKey() when a test has to
     * authenticate with it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            ...$this->attributesFor(KeyGenerator::generate(KeyType::Secret, KeyMode::Live)),
        ];
    }

    /**
     * The row for a known raw key: type, mode, prefix, hash and the shape its type requires.
     */
    public function withRawKey(string $rawKey): static
    {
        return $this->state(fn () => $this->attributesFor($rawKey));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(string $rawKey): array
    {
        $parsed = KeyGenerator::parse($rawKey) ?? throw new InvalidArgumentException('The factory needs a well-formed key.');
        $secret = $parsed->type === KeyType::Secret;

        return [
            'type' => $parsed->type,
            'mode' => $parsed->mode,
            'key_prefix' => KeyGenerator::prefixOf($rawKey),
            'key_hash' => KeyGenerator::hash($rawKey),
            'scopes' => $secret ? [Scope::Wildcard->value] : [],
            'allowed_origins' => $secret ? null : ['https://app.example.com'],
        ];
    }
}
