<?php

namespace App\Http\Resources;

use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;

/**
 * The create response: the endpoint plus the signing `secret`, shown this once. A separate class, so
 * that no other response can carry it by accident.
 *
 * @mixin WebhookEndpoint
 */
class CreatedWebhookEndpointResource extends WebhookEndpointResource
{
    public function __construct(WebhookEndpoint $endpoint, #[\SensitiveParameter] private readonly string $secret)
    {
        parent::__construct($endpoint);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'secret' => $this->secret];
    }
}
