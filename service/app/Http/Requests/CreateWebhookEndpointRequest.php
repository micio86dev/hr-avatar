<?php

namespace App\Http\Requests;

use App\Webhooks\Outbound\EventType;

class CreateWebhookEndpointRequest extends WebhookEndpointRequest
{
    protected function presence(): string
    {
        return 'required';
    }

    /**
     * The members of a new endpoint: every event unless some are named.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return ['events' => EventType::values(), ...$this->changes()];
    }
}
