<?php

use App\Conversations\ConversationLifecycle;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Jobs\DeliverWebhook;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->account = Account::factory()->create();
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    $this->lifecycle = app(ConversationLifecycle::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function emissionConversation(ConversationStatus $status, array $attributes = []): Conversation
{
    return inAccount(test()->account, fn () => Conversation::factory()->create([
        'avatar_config_id' => test()->config->id, 'status' => $status, 'external_ref' => 'order-9', 'metadata' => ['a' => ['b' => 1]],
        'connecting_at' => now()->subMinutes(2), 'provider_session_ref' => 'sess-1', ...$attributes,
    ]));
}

/**
 * @return list<array<string, mixed>>
 */
function emitted(Conversation $conversation, ?string $type = null): array
{
    return WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)
        ->when($type !== null, fn ($query) => $query->where('event_type', $type))
        ->orderBy('created_at')->orderBy('id')->get()
        ->map(fn (WebhookDelivery $delivery) => json_decode($delivery->payload, true))->all();
}

it('emits conversation.live once when a conversation becomes live', function () {
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
    $conversation = emissionConversation(ConversationStatus::Connecting);

    $this->lifecycle->transition($conversation, ConversationStatus::Live);
    $this->lifecycle->transition($conversation, ConversationStatus::Live);

    $events = emitted($conversation);
    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('conversation.live')
        ->and($events[0]['id'])->toStartWith('evt_')
        ->and($events[0]['data']['conversation'])->toMatchArray(['id' => $conversation->id, 'status' => 'live', 'external_ref' => 'order-9', 'metadata' => ['a' => ['b' => 1]]]);
    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('emits conversation.ended for a failure, with the status failed and its reason', function () {
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
    $conversation = emissionConversation(ConversationStatus::Connecting, ['provider_session_ref' => null]);

    $this->lifecycle->transition($conversation, ConversationStatus::Failed, EndedReason::ProviderError);

    $ended = emitted($conversation, 'conversation.ended');
    expect($ended)->toHaveCount(1)
        ->and($ended[0]['data']['conversation'])->toMatchArray(['status' => 'failed', 'ended_reason' => 'provider_error'])
        ->and($ended[0]['data']['conversation']['ended_at'])->not->toBeNull();
});

it('emits conversation.ended once when a conversation ends, whoever asks', function () {
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
    $conversation = emissionConversation(ConversationStatus::Live, ['live_at' => now()->subMinute()]);

    $this->lifecycle->end($conversation, EndedReason::Api);
    $this->lifecycle->end($conversation, EndedReason::Client);

    $ended = emitted($conversation, 'conversation.ended');
    expect($ended)->toHaveCount(1)->and($ended[0]['data']['conversation'])->toMatchArray(['status' => 'ended', 'ended_reason' => 'api']);
});

it('emits transcript.ready with the transcript\'s status and size, and no text, when there was nothing to wait for', function () {
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
    $conversation = emissionConversation(ConversationStatus::Created, ['provider_session_ref' => null, 'connecting_at' => null]);

    $this->lifecycle->end($conversation, EndedReason::Api);

    $ready = emitted($conversation, 'transcript.ready');
    expect($ready)->toHaveCount(1)
        ->and($ready[0]['data']['transcript'])->toBe(['status' => 'ready', 'utterance_count' => 0])
        ->and($ready[0]['data']['conversation']['transcript_status'])->toBe('ready')
        ->and(array_keys($ready[0]['data']))->toBe(['conversation', 'transcript']);
});

it('counts the utterances the conversation holds in transcript.ready', function () {
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
    $conversation = emissionConversation(ConversationStatus::Live, ['provider_session_ref' => null]);
    inAccount($this->account, fn () => ConversationEvent::forceCreate([
        'conversation_id' => $conversation->id, 'sequence' => 1, 'source' => 'client', 'type' => 'utterance', 'role' => 'user', 'text' => 'Hello', 'idempotency_key' => 'k-1',
    ]));

    $this->lifecycle->end($conversation, EndedReason::Client);

    expect(emitted($conversation, 'transcript.ready')[0]['data']['transcript']['utterance_count'])->toBe(1);
});

it('delivers only to the endpoints that subscribe to the event, and only the account\'s own', function () {
    $all = inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
    $endedOnly = inAccount($this->account, fn () => WebhookEndpoint::factory()->create(['events' => ['conversation.ended']]));
    $disabled = inAccount($this->account, fn () => WebhookEndpoint::factory()->create(['enabled' => false]));
    $stranger = Account::factory()->create();
    $foreign = inAccount($stranger, fn () => WebhookEndpoint::factory()->create());
    $conversation = emissionConversation(ConversationStatus::Connecting);

    $this->lifecycle->transition($conversation, ConversationStatus::Live);
    $this->lifecycle->end($conversation, EndedReason::Api);

    $byEndpoint = WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->get()->groupBy('webhook_endpoint_id')
        ->map(fn ($rows) => $rows->map(fn (WebhookDelivery $delivery) => $delivery->event_type->value)->sort()->values()->all());
    expect($byEndpoint[$all->id])->toBe(['conversation.ended', 'conversation.live'])
        ->and($byEndpoint[$endedOnly->id])->toBe(['conversation.ended'])
        ->and($byEndpoint->has($disabled->id))->toBeFalse()
        ->and($byEndpoint->has($foreign->id))->toBeFalse();
});

it('shares one event id across the endpoints of one emission, and a new one for the next', function () {
    inAccount($this->account, fn () => WebhookEndpoint::factory()->count(2)->create());
    $conversation = emissionConversation(ConversationStatus::Connecting);

    $this->lifecycle->transition($conversation, ConversationStatus::Live);
    $this->lifecycle->end($conversation, EndedReason::Api);

    $ids = WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->get()->groupBy(fn (WebhookDelivery $delivery) => $delivery->event_type->value)->map(fn ($rows) => $rows->pluck('event_id')->unique()->values()->all());
    expect($ids['conversation.live'])->toHaveCount(1)->and($ids['conversation.ended'])->toHaveCount(1)->and($ids['conversation.live'][0])->not->toBe($ids['conversation.ended'][0]);
});
