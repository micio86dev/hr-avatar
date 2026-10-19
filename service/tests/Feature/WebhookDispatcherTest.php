<?php

use App\Accounts\CurrentAccount;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptStatus;
use App\Jobs\DeliverWebhook;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Outbound\DeliveryStatus;
use App\Webhooks\Outbound\EventType;
use App\Webhooks\Outbound\WebhookDispatcher;
use App\Webhooks\Outbound\WebhookSigner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-10-19 12:00:00');
    $this->account = Account::factory()->create();
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function dispatchConversation(array $attributes = [], ?Account $account = null): Conversation
{
    $account ??= test()->account;
    $config = $account->is(test()->account) ? test()->config : inAccount($account, fn () => AvatarConfig::factory()->create());

    return inAccount($account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id, ...$attributes]));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function dispatchEndpoint(array $attributes = [], ?Account $account = null): WebhookEndpoint
{
    return inAccount($account ?? test()->account, fn () => WebhookEndpoint::factory()->create($attributes));
}

/**
 * @return list<WebhookDelivery>
 */
function deliveriesOf(Conversation $conversation): array
{
    return WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('created_at')->orderBy('id')->get()->all();
}

describe('who gets a delivery', function () {
    it('creates one pending delivery for each enabled endpoint of the account that is subscribed', function () {
        $first = dispatchEndpoint(['events' => ['conversation.ended']]);
        $second = dispatchEndpoint(['events' => ['conversation.live', 'conversation.ended']]);
        $conversation = dispatchConversation(['status' => ConversationStatus::Ended, 'ended_reason' => EndedReason::Api]);

        $ids = app(WebhookDispatcher::class)->record($conversation, EventType::ConversationEnded);

        $deliveries = deliveriesOf($conversation);
        expect($ids)->toHaveCount(2)
            ->and(array_column($deliveries, 'webhook_endpoint_id'))->toEqualCanonicalizing([$first->id, $second->id])
            ->and(array_map(fn ($d) => $d->status, $deliveries))->each->toBe(DeliveryStatus::Pending)
            ->and(array_map(fn ($d) => $d->attempts, $deliveries))->each->toBe(0)
            ->and(array_map(fn ($d) => $d->event_type, $deliveries))->each->toBe(EventType::ConversationEnded)
            ->and(array_map(fn ($d) => $d->account_id, $deliveries))->each->toBe($this->account->id);
    });

    it('skips an endpoint that is not subscribed to the event', function () {
        dispatchEndpoint(['events' => ['conversation.ended']]);
        $conversation = dispatchConversation(['status' => ConversationStatus::Live]);

        expect(app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive))->toBe([])
            ->and(deliveriesOf($conversation))->toBe([]);
        Queue::assertNothingPushed();
    });

    it('skips a disabled endpoint, whatever it is subscribed to', function () {
        dispatchEndpoint(['enabled' => false]);
        $conversation = dispatchConversation();

        expect(app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive))->toBe([]);
        Queue::assertNothingPushed();
    });

    it('never reaches the endpoints of another account', function () {
        $other = Account::factory()->create();
        dispatchEndpoint([], $other);
        $mine = dispatchEndpoint();
        $conversation = dispatchConversation();

        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);

        expect(array_column(deliveriesOf($conversation), 'webhook_endpoint_id'))->toBe([$mine->id]);
    });

    it('does not need the account to be bound by the caller, and leaves the binding as it found it', function () {
        dispatchEndpoint();
        $conversation = dispatchConversation();
        expect(app(CurrentAccount::class)->id())->toBeNull();

        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);

        expect(deliveriesOf($conversation))->toHaveCount(1)->and(app(CurrentAccount::class)->id())->toBeNull();
    });
});

describe('the body', function () {
    it('is the exact envelope: id, type, created_at and the conversation, with the host\'s own data unchanged', function () {
        dispatchEndpoint();
        $metadata = json_decode('{"z":1,"a":{"b":{},"c":[]},"é":"ü/x"}', false);
        $conversation = dispatchConversation([
            'status' => ConversationStatus::Ended, 'ended_reason' => EndedReason::Api, 'external_ref' => 'order-9', 'metadata' => $metadata,
            'live_at' => '2026-10-19 11:50:00', 'ended_at' => '2026-10-19 11:59:00',
            // None of these may be in the body.
            'provider_session_ref' => 'sess-ref-77', 'provider_context_ref' => 'ctx-ref-88', 'callback_token_hash' => hash('sha256', 'cb'), 'system_prompt' => 'SECRET-PROMPT', 'opening_text' => 'OPENING',
        ]);

        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationEnded);

        $payload = deliveriesOf($conversation)[0]->payload;
        $decoded = json_decode($payload, false);
        $expected = (new WebhookSigner)->encode([
            'id' => $decoded->id,
            'type' => 'conversation.ended',
            'created_at' => '2026-10-19T12:00:00Z',
            'data' => ['conversation' => [
                'id' => $conversation->id,
                'status' => 'ended',
                'avatar_config_id' => $this->config->id,
                'external_ref' => 'order-9',
                'metadata' => $metadata,
                'ended_reason' => 'api',
                'created_at' => $conversation->created_at->toIso8601ZuluString(),
                'live_at' => '2026-10-19T11:50:00Z',
                'ended_at' => '2026-10-19T11:59:00Z',
                'transcript_status' => 'pending',
            ]],
        ]);
        expect($payload)->toBe($expected)
            ->and($decoded->id)->toMatch('/^evt_[0-9a-z]{26}$/')
            ->and($payload)->toContain('"metadata":{"z":1,"a":{"b":{},"c":[]},"é":"ü/x"}');
        foreach (['sess-ref-77', 'ctx-ref-88', hash('sha256', 'cb'), 'SECRET-PROMPT', 'OPENING', 'client_secret', 'provider_session_ref'] as $internal) {
            expect($payload)->not->toContain($internal);
        }
    });

    it('keeps an empty metadata an object and a missing external ref null', function () {
        dispatchEndpoint();
        $conversation = dispatchConversation();

        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);

        expect(deliveriesOf($conversation)[0]->payload)->toContain('"external_ref":null,"metadata":{},"ended_reason":null');
    });

    it('describes a conversation that failed as ended with status failed', function () {
        dispatchEndpoint();
        $conversation = dispatchConversation(['status' => ConversationStatus::Failed, 'ended_reason' => EndedReason::ProviderError, 'ended_at' => now()]);

        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationEnded);

        $data = json_decode(deliveriesOf($conversation)[0]->payload, true)['data']['conversation'];
        expect($data['status'])->toBe('failed')->and($data['ended_reason'])->toBe('provider_error');
    });

    it('adds the transcript status and utterance count, and no text, to transcript.ready', function (TranscriptStatus $status) {
        dispatchEndpoint();
        $conversation = dispatchConversation(['status' => ConversationStatus::Ended, 'transcript_status' => $status]);

        app(WebhookDispatcher::class)->record($conversation, EventType::TranscriptReady, 7);

        $decoded = json_decode(deliveriesOf($conversation)[0]->payload, true);
        expect($decoded['type'])->toBe('transcript.ready')
            ->and($decoded['data']['transcript'])->toBe(['status' => $status->value, 'utterance_count' => 7])
            ->and($decoded['data']['conversation']['transcript_status'])->toBe($status->value)
            ->and(array_keys($decoded['data']))->toBe(['conversation', 'transcript']);
    })->with([TranscriptStatus::Ready, TranscriptStatus::Partial]);

    it('has no transcript member on the other events', function (EventType $type) {
        dispatchEndpoint();
        $conversation = dispatchConversation();

        app(WebhookDispatcher::class)->record($conversation, $type, 3);

        expect(array_keys(json_decode(deliveriesOf($conversation)[0]->payload, true)['data']))->toBe(['conversation']);
    })->with([EventType::ConversationLive, EventType::ConversationEnded]);

    it('shares one event id across the endpoints of one emission, and uses a new one for the next event', function () {
        dispatchEndpoint();
        dispatchEndpoint();
        $conversation = dispatchConversation();

        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);
        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationEnded);

        $ids = array_map(fn (WebhookDelivery $d) => [$d->event_type->value, $d->event_id], deliveriesOf($conversation));
        $live = array_unique(array_column(array_filter($ids, fn ($i) => $i[0] === 'conversation.live'), 1));
        $ended = array_unique(array_column(array_filter($ids, fn ($i) => $i[0] === 'conversation.ended'), 1));
        expect($live)->toHaveCount(1)->and($ended)->toHaveCount(1)->and($live)->not->toBe($ended);
    });

    it('stores the payload where the event id column says: the id inside the body is the event id', function () {
        dispatchEndpoint();
        $conversation = dispatchConversation();

        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);

        $delivery = deliveriesOf($conversation)[0];
        expect(json_decode($delivery->payload)->id)->toBe($delivery->event_id);
    });
});

describe('once per transition', function () {
    it('records an event again as a no-op: no second row, no second job', function () {
        dispatchEndpoint();
        $conversation = dispatchConversation();
        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);

        $again = app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);

        expect($again)->toBe([])->and(deliveriesOf($conversation))->toHaveCount(1);
        Queue::assertPushed(DeliverWebhook::class, 1);
    });

    it('gives an endpoint added since the first emission its own delivery', function () {
        dispatchEndpoint();
        $conversation = dispatchConversation();
        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);
        $late = dispatchEndpoint();

        $created = app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);

        expect($created)->toHaveCount(1)->and(deliveriesOf($conversation))->toHaveCount(2)
            ->and(WebhookDelivery::withoutGlobalScopes()->find($created[0])->webhook_endpoint_id)->toBe($late->id);
    });

    it('keeps the uniqueness in the database', function () {
        $endpoint = dispatchEndpoint();
        $conversation = dispatchConversation();
        $row = fn () => [
            'id' => strtolower((string) Str::ulid()), 'account_id' => $this->account->id, 'webhook_endpoint_id' => $endpoint->id,
            'conversation_id' => $conversation->id, 'event_id' => 'evt_x', 'event_type' => 'conversation.live', 'payload' => '{}',
            'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('webhook_deliveries')->insert($row());

        expect(fn () => DB::transaction(fn () => DB::table('webhook_deliveries')->insert($row())))->toThrow(QueryException::class);
    });
});

describe('the job it queues', function () {
    it('carries the account and delivery ids and nothing else, is set to wait for the commit, and makes no request itself', function () {
        Http::fake();
        $endpoint = dispatchEndpoint();
        $conversation = dispatchConversation();

        $ids = app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->accountId === $this->account->id && $job->deliveryId === $ids[0] && $job->afterCommit === true);
        Http::assertNothingSent();
        $serialized = serialize(Queue::pushed(DeliverWebhook::class)->first());
        expect($serialized)->not->toContain($endpoint->secret)->not->toContain($endpoint->url)->not->toContain('conversation.live');
    });
});

describe('the delivery row', function () {
    it('hides the payload from serialization', function () {
        dispatchEndpoint();
        $conversation = dispatchConversation(['external_ref' => 'HOST-REF-1']);
        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);

        $delivery = deliveriesOf($conversation)[0];

        expect($delivery->toArray())->not->toHaveKey('payload')->and(json_encode($delivery))->not->toContain('HOST-REF-1')->not->toContain('"payload"');
    });

    it('goes with its endpoint and with its conversation', function () {
        $endpoint = dispatchEndpoint();
        $conversation = dispatchConversation();
        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);

        inAccount($this->account, fn () => $endpoint->delete());

        expect(deliveriesOf($conversation))->toBe([]);
    });

    it('is invisible to another account', function () {
        dispatchEndpoint();
        $conversation = dispatchConversation();
        app(WebhookDispatcher::class)->record($conversation, EventType::ConversationLive);

        expect(inAccount(Account::factory()->create(), fn () => WebhookDelivery::query()->count()))->toBe(0)
            ->and(inAccount($this->account, fn () => WebhookDelivery::query()->count()))->toBe(1);
    });

    it('refuses values outside the checks', function (array $overrides) {
        $endpoint = dispatchEndpoint();
        $conversation = dispatchConversation();
        $row = [
            'id' => strtolower((string) Str::ulid()), 'account_id' => $this->account->id, 'webhook_endpoint_id' => $endpoint->id,
            'conversation_id' => $conversation->id, 'event_id' => 'evt_x', 'event_type' => 'conversation.live', 'payload' => '{}',
            'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(), ...$overrides,
        ];

        expect(fn () => DB::transaction(fn () => DB::table('webhook_deliveries')->insert($row)))->toThrow(QueryException::class);
    })->with([
        'unknown status' => [['status' => 'dead']],
        'unknown event type' => [['event_type' => 'conversation.exploded']],
        'negative attempts' => [['attempts' => -1]],
        'unknown endpoint' => [['webhook_endpoint_id' => 'we_missing']],
    ]);
});
