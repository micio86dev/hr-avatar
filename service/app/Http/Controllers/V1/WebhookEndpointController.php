<?php

namespace App\Http\Controllers\V1;

use App\Accounts\CurrentAccount;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Http\Requests\CreateWebhookEndpointRequest;
use App\Http\Requests\PaginationRequest;
use App\Http\Requests\UpdateWebhookEndpointRequest;
use App\Http\Resources\CreatedWebhookEndpointResource;
use App\Http\Resources\PagedCollection;
use App\Http\Resources\WebhookEndpointResource;
use App\Models\Account;
use App\Models\WebhookEndpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The URLs that receive the account's signed webhook events. Requires the `webhook_endpoints:manage` scope.
 * Each event is a POST with `X-HRA-Signature`, `X-HRA-Timestamp`, `X-HRA-Event-Id` and `X-HRA-Event-Type`
 * headers; the signature is `v1=` plus the hex HMAC-SHA256, keyed with the endpoint's secret, of
 * `"{timestamp}.{body}"`. A receiver rejects a timestamp more than 300 seconds from its own clock and
 * deduplicates on the event id.
 */
#[Group('Webhook endpoints')]
class WebhookEndpointController
{
    /** At most this many endpoints per account. */
    private const MAX_PER_ACCOUNT = 20;

    /**
     * List webhook endpoints
     *
     * The account's endpoints, newest first. The secret is never returned after creation.
     */
    public function index(PaginationRequest $request): PagedCollection
    {
        return new PagedCollection($request->page(WebhookEndpoint::query()), WebhookEndpointResource::class);
    }

    /**
     * Create a webhook endpoint
     *
     * The response carries the signing `secret` once (`hra_whsec_` and 64 hex characters); it cannot be
     * read afterwards. The URL must be https, at most 2048 characters, without credentials, and must not
     * point at a private or reserved address (plain http, and a private address, are accepted only where destinations are
     * not enforced: the `local` and `testing` environments).
     * An account holds at most 20 endpoints; the 21st is a 422.
     */
    public function store(CreateWebhookEndpointRequest $request): JsonResponse
    {
        $secret = WebhookEndpoint::newSecret();

        $endpoint = DB::transaction(function () use ($request, $secret): WebhookEndpoint {
            $this->lockAccountRow();

            if (WebhookEndpoint::query()->count() >= self::MAX_PER_ACCOUNT) {
                throw new ApiProblem(ErrorCode::ValidationFailed, 'An account can hold at most '.self::MAX_PER_ACCOUNT.' webhook endpoints.');
            }

            $endpoint = new WebhookEndpoint($request->values());
            $endpoint->secret = $secret;
            $endpoint->save();

            return $endpoint->refresh();
        });

        return (new CreatedWebhookEndpointResource($endpoint, $secret))->response()->setStatusCode(201);
    }

    /**
     * Show a webhook endpoint
     *
     * One endpoint of the account, without its secret. An endpoint of another account is a 404.
     */
    public function show(WebhookEndpoint $webhookEndpoint): WebhookEndpointResource
    {
        return new WebhookEndpointResource($webhookEndpoint);
    }

    /**
     * Update a webhook endpoint
     *
     * Each member sent replaces the stored one; members left out are kept. A new URL is checked like the
     * URL of a new endpoint. The secret cannot be changed.
     */
    public function update(UpdateWebhookEndpointRequest $request, WebhookEndpoint $webhookEndpoint): WebhookEndpointResource
    {
        $webhookEndpoint->fill($request->changes())->save();

        return new WebhookEndpointResource($webhookEndpoint->refresh());
    }

    /**
     * Delete a webhook endpoint
     *
     * Deliveries still waiting for a retry stop with it.
     */
    public function destroy(WebhookEndpoint $webhookEndpoint): Response
    {
        $webhookEndpoint->delete();

        return response()->noContent();
    }

    /**
     * Serializes the creates of one account: the lock is held until the transaction ends, so a second create waits
     * here and counts afterwards, and two creates cannot both take the last slot. Only the lock matters; the row is not read.
     */
    private function lockAccountRow(): void
    {
        Account::query()->whereKey(app(CurrentAccount::class)->require())->lockForUpdate()->first();
    }
}
