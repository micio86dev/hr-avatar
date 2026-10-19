<?php

namespace Database\Factories;

use App\Models\ConversationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationEvent>
 */
class ConversationEventFactory extends Factory
{
    protected $model = ConversationEvent::class;

    /**
     * A client utterance. The conversation id and, for more than one event, the sequence and the idempotency key are the test's to give.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sequence' => 1,
            'source' => 'client',
            'type' => 'utterance',
            'role' => 'user',
            'text' => fake()->sentence(),
            'idempotency_key' => fake()->unique()->uuid(),
        ];
    }
}
