<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Outbound\DeliveryStatus;
use App\Webhooks\Outbound\EventType;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const DELIVERY_LOG_MEMBERS = ['id', 'event_id', 'event_type', 'status', 'attempts', 'last_response_status', 'last_error', 'next_attempt_at', 'created_at', 'completed_at'];

beforeEach(function () {
    Queue::fake();
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['webhook_endpoints:manage']]);
    [, $this->noScope] = issueKey($this->account, ['scopes' => ['conversations:read']]);
    $this->endpoint = inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
    $this->conversation = inAccount($this->account, fn () => Conversation::factory()->create());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function logDelivery(array $attributes = [], ?WebhookEndpoint $endpoint = null, ?Conversation $conversation = null): WebhookDelivery
{
    $endpoint ??= test()->endpoint;
    $conversation ??= inAccount($endpoint->account_id === test()->account->id ? test()->account : Account::find($endpoint->account_id), fn () => Conversation::factory()->create());

    return inAccount(Account::findOrFail($endpoint->account_id), fn () => WebhookDelivery::forceCreate([
        'id' => strtolower((string) Str::ulid()),
        'account_id' => $endpoint->account_id,
        'webhook_endpoint_id' => $endpoint->id,
        'conversation_id' => $conversation->id,
        'event_id' => 'evt_'.strtolower((string) Str::ulid()),
        'event_type' => EventType::ConversationEnded,
        'payload' => '{"PAYLOAD":"SECRET-PAYLOAD-BODY"}',
        'status' => DeliveryStatus::Pending,
        'attempts' => 0,
        ...$attributes,
    ]))->refresh();
}

function logGet(string $uri, ?string $raw = null): TestResponse
{
    return test()->getJson($uri, ['Authorization' => 'Bearer '.($raw ?? test()->raw)]);
}

describe('GET /v1/webhook-endpoints/{id}/deliveries', function () {
    it('shows what happened to a delivery that failed with HTTP 500: pending, the status, the code, the next attempt', function () {
        $delivery = logDelivery([
            'attempts' => 1, 'last_response_status' => 500, 'last_error' => 'http_500', 'last_attempt_at' => now(), 'next_attempt_at' => now()->addSeconds(10),
        ], conversation: $this->conversation);

        $response = logGet("/v1/webhook-endpoints/{$this->endpoint->id}/deliveries")->assertOk();

        $entry = $response->json('data.0');
        expect(array_keys($entry))->toBe(DELIVERY_LOG_MEMBERS)
            ->and($entry['id'])->toBe($delivery->id)
            ->and($entry['event_id'])->toBe($delivery->event_id)
            ->and($entry['event_type'])->toBe('conversation.ended')
            ->and($entry['status'])->toBe('pending')
            ->and($entry['attempts'])->toBe(1)
            ->and($entry['last_response_status'])->toBe(500)
            ->and($entry['last_error'])->toBe('http_500')
            ->and($entry['next_attempt_at'])->not->toBeNull()
            ->and($entry['created_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/')
            ->and($entry['completed_at'])->toBeNull()
            ->and($response->json('next_cursor'))->toBeNull();
    });

    it('shows a finished delivery with its completion time and no retry', function () {
        logDelivery(['status' => DeliveryStatus::Succeeded, 'attempts' => 2, 'last_response_status' => 204, 'completed_at' => now(), 'next_attempt_at' => null]);

        $entry = logGet("/v1/webhook-endpoints/{$this->endpoint->id}/deliveries")->assertOk()->json('data.0');

        expect($entry['status'])->toBe('succeeded')->and($entry['completed_at'])->not->toBeNull()->and($entry['next_attempt_at'])->toBeNull()->and($entry['last_error'])->toBeNull();
    });

    it('carries neither the payload, a response body, the signing secret nor the endpoint url', function () {
        logDelivery(['attempts' => 1, 'last_response_status' => 500, 'last_error' => 'http_500']);

        $body = logGet("/v1/webhook-endpoints/{$this->endpoint->id}/deliveries")->assertOk()->getContent();

        foreach (['SECRET-PAYLOAD-BODY', 'PAYLOAD', 'payload', $this->endpoint->secret, 'hra_whsec_', $this->endpoint->url, 'secret'] as $forbidden) {
            expect($body)->not->toContain($forbidden);
        }
    });

    it('filters by status', function () {
        logDelivery(['status' => DeliveryStatus::Pending]);
        logDelivery(['status' => DeliveryStatus::Failed, 'completed_at' => now()]);
        logDelivery(['status' => DeliveryStatus::Failed, 'completed_at' => now()]);
        logDelivery(['status' => DeliveryStatus::Succeeded, 'completed_at' => now()]);
        $uri = "/v1/webhook-endpoints/{$this->endpoint->id}/deliveries";

        expect(array_column(logGet("{$uri}?status=failed")->assertOk()->json('data'), 'status'))->toBe(['failed', 'failed'])
            ->and(array_column(logGet("{$uri}?status=pending")->assertOk()->json('data'), 'status'))->toBe(['pending'])
            ->and(array_column(logGet("{$uri}?status=succeeded")->assertOk()->json('data'), 'status'))->toBe(['succeeded'])
            ->and(logGet($uri)->assertOk()->json('data'))->toHaveCount(4);
    });

    it('refuses a status that is none', function (mixed $status) {
        $response = logGet("/v1/webhook-endpoints/{$this->endpoint->id}/deliveries?status={$status}")->assertStatus(422);

        expect($response->json('errors'))->toHaveKey('status');
    })->with(['dead', 'FAILED', 'delivered', '1']);

    it('lists the newest first, in pages', function () {
        $old = logDelivery(['created_at' => now()->subMinutes(3)]);
        $middle = logDelivery(['created_at' => now()->subMinutes(2)]);
        $new = logDelivery(['created_at' => now()->subMinute()]);
        $uri = "/v1/webhook-endpoints/{$this->endpoint->id}/deliveries";

        $page = logGet("{$uri}?limit=2")->assertOk();
        expect(array_column($page->json('data'), 'id'))->toBe([$new->id, $middle->id])->and($page->json('next_cursor'))->not->toBeNull();

        $next = logGet("{$uri}?limit=2&cursor=".$page->json('next_cursor'))->assertOk();
        expect(array_column($next->json('data'), 'id'))->toBe([$old->id])->and($next->json('next_cursor'))->toBeNull();
    });

    it('keeps the status filter across pages', function () {
        foreach (range(1, 3) as $minutes) {
            logDelivery(['status' => DeliveryStatus::Failed, 'completed_at' => now(), 'created_at' => now()->subMinutes($minutes)]);
            logDelivery(['status' => DeliveryStatus::Pending, 'created_at' => now()->subMinutes($minutes)]);
        }
        $uri = "/v1/webhook-endpoints/{$this->endpoint->id}/deliveries?status=failed&limit=2";

        $page = logGet($uri)->assertOk();
        $next = logGet($uri.'&cursor='.$page->json('next_cursor'))->assertOk();

        expect($page->json('data'))->toHaveCount(2)->and($next->json('data'))->toHaveCount(1)
            ->and(array_unique(array_column([...$page->json('data'), ...$next->json('data')], 'status')))->toBe(['failed']);
    });

    it('lists only the deliveries of that endpoint', function () {
        $other = inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
        $mine = logDelivery();
        logDelivery([], $other);

        expect(array_column(logGet("/v1/webhook-endpoints/{$this->endpoint->id}/deliveries")->json('data'), 'id'))->toBe([$mine->id]);
    });

    it('is empty for an endpoint nothing was sent to', function () {
        expect(logGet("/v1/webhook-endpoints/{$this->endpoint->id}/deliveries")->assertOk()->json())->toBe(['data' => [], 'next_cursor' => null]);
    });

    it('answers 404 for the endpoint of another account, and for one that does not exist', function () {
        $foreignAccount = Account::factory()->create();
        $foreign = inAccount($foreignAccount, fn () => WebhookEndpoint::factory()->create());
        logDelivery([], $foreign);

        logGet("/v1/webhook-endpoints/{$foreign->id}/deliveries")->assertNotFound()->assertJsonPath('code', 'not_found');
        logGet('/v1/webhook-endpoints/we_01j0000000000000000000000x/deliveries')->assertNotFound();
    });

    it('needs the manage scope and a key', function () {
        logGet("/v1/webhook-endpoints/{$this->endpoint->id}/deliveries", $this->noScope)->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
        $this->getJson("/v1/webhook-endpoints/{$this->endpoint->id}/deliveries")->assertStatus(401);
    });
});
