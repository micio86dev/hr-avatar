<?php

namespace App\Http\Resources;

use App\Models\WebhookDelivery;
use App\Webhooks\Outbound\DeliveryStatus;
use App\Webhooks\Outbound\EventType;
use Illuminate\Http\Request;

/**
 * One delivery as the log shows it. The member list is explicit: the payload (the host's data), the
 * signing secret and the endpoint's URL can never reach a response by being added to the model.
 *
 * @mixin WebhookDelivery
 */
class WebhookDeliveryResource extends ApiResource
{
    /**
     * @return array{id: string, event_id: string, event_type: EventType, status: DeliveryStatus, attempts: int, last_response_status: int|null, last_error: string|null, next_attempt_at: string|null, created_at: string, completed_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'event_type' => $this->event_type,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'last_response_status' => $this->last_response_status,
            'last_error' => $this->last_error,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
