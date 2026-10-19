<?php

namespace App\Http\Resources;

use App\Models\WebhookEndpoint;
use App\Webhooks\Outbound\EventType;
use Illuminate\Http\Request;

/**
 * A webhook endpoint as the API shows it. The member list is explicit: the secret and the owner can
 * never reach a response by being added to the model.
 *
 * @mixin WebhookEndpoint
 */
class WebhookEndpointResource extends ApiResource
{
    /**
     * @return array{id: string, url: string, events: list<EventType>, enabled: bool, description: string|null, created_at: string, updated_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'events' => array_map(EventType::from(...), $this->events),
            'enabled' => $this->enabled,
            'description' => $this->description,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
