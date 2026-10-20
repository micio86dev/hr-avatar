<?php

namespace App\Http\Requests;

use App\Webhooks\Outbound\DeliveryStatus;
use Illuminate\Validation\Rule;

/**
 * A page of an endpoint's deliveries, optionally only those in one status.
 */
class ListWebhookDeliveriesRequest extends PaginationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'status' => ['sometimes', 'string', Rule::enum(DeliveryStatus::class)]];
    }

    /**
     * The status to keep, or null for all.
     */
    public function status(): ?DeliveryStatus
    {
        return $this->has('status') ? DeliveryStatus::from($this->string('status')->toString()) : null;
    }
}
