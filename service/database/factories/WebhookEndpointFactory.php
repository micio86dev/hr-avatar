<?php

namespace Database\Factories;

use App\Models\WebhookEndpoint;
use App\Webhooks\Outbound\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    /**
     * An enabled endpoint subscribed to every event.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'url' => 'https://'.fake()->unique()->domainWord().'.example.com/hook',
            'events' => EventType::values(),
            'enabled' => true,
            'secret' => WebhookEndpoint::newSecret(),
        ];
    }
}
