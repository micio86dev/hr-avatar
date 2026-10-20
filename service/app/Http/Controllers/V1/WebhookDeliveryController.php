<?php

namespace App\Http\Controllers\V1;

use App\Http\Requests\ListWebhookDeliveriesRequest;
use App\Http\Resources\PagedCollection;
use App\Http\Resources\WebhookDeliveryResource;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Dedoc\Scramble\Attributes\Group;

/**
 * What happened to the events sent to an endpoint. Requires the `webhook_endpoints:manage` scope.
 */
#[Group('Webhook endpoints')]
class WebhookDeliveryController
{
    /**
     * List the deliveries of a webhook endpoint
     *
     * Newest first, optionally only those in one `status`. Each entry says how many attempts were made,
     * the last response status, a short `last_error` code (`timeout`, `connection_failed`,
     * `destination_not_allowed`, `destination_unresolved`, `endpoint_disabled`, `internal_error` or `http_<status>`) and, for a delivery
     * waiting for a retry, when the next attempt is due. Neither the body that was sent nor anything a
     * receiver answered is ever returned. An endpoint of another account is a 404.
     */
    public function index(ListWebhookDeliveriesRequest $request, WebhookEndpoint $webhookEndpoint): PagedCollection
    {
        $query = WebhookDelivery::query()->where('webhook_endpoint_id', $webhookEndpoint->id);

        if ($request->status() !== null) {
            $query->where('status', $request->status()->value);
        }

        return new PagedCollection($request->page($query), WebhookDeliveryResource::class);
    }
}
