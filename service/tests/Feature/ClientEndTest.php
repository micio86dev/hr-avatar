<?php

use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Jobs\ReleaseProviderSession;
use App\Keys\ClientSecret;
use App\Keys\KeyType;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Queue::fake();
    $this->account = Account::factory()->create();
    [, $this->pk] = issueKey($this->account, type: KeyType::Publishable);
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function clientEndable(ConversationStatus $status, array $attributes = []): Conversation
{
    $implied = match ($status) {
        ConversationStatus::Created => [],
        ConversationStatus::Connecting, ConversationStatus::Live => ['connecting_at' => now()->subMinutes(2), 'provider_session_ref' => 'sess-1'],
        ConversationStatus::Ended => ['connecting_at' => now()->subMinutes(5), 'ended_at' => now()->subSeconds(20), 'ended_reason' => EndedReason::Api, 'provider_session_ref' => 'sess-1'],
        ConversationStatus::Failed => ['connecting_at' => now()->subMinutes(5), 'ended_at' => now()->subSeconds(20), 'ended_reason' => EndedReason::ProviderError],
    };

    return inAccount(test()->account, fn () => Conversation::factory()->create(['avatar_config_id' => test()->config->id, 'status' => $status, ...$implied, ...$attributes]));
}

function clientEnd(Conversation $conversation, ?string $secret = null): TestResponse
{
    return test()->postJson("/v1/client/conversations/{$conversation->id}/end", [], browserHeaders(test()->pk, $secret ?? clientSecretFor($conversation)));
}

function clientEndEvents(Conversation $conversation): int
{
    return WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('event_type', 'conversation.ended')->count();
}

it('ends a live conversation with the reason client and queues the release of its session', function () {
    $conversation = clientEndable(ConversationStatus::Live, ['live_at' => now()->subMinute()]);

    $response = clientEnd($conversation)->assertOk();

    expect(array_keys($response->json()))->toBe(CONVERSATION_MEMBERS)
        ->and($response->json('status'))->toBe('ended')
        ->and($response->json('ended_reason'))->toBe('client')
        ->and(clientEndEvents($conversation))->toBe(1);
    Queue::assertPushed(ReleaseProviderSession::class, fn (ReleaseProviderSession $job) => $job->conversationId === $conversation->id);
});

it('answers 200 with the unchanged conversation when it is ended again, whoever ended it first', function (ConversationStatus $status) {
    $conversation = clientEndable($status);
    $before = Conversation::withoutGlobalScopes()->findOrFail($conversation->id)->getAttributes();

    $response = clientEnd($conversation)->assertOk();

    expect($response->json('status'))->toBe($status->value)
        ->and(Conversation::withoutGlobalScopes()->findOrFail($conversation->id)->getAttributes())->toBe($before)
        ->and(clientEndEvents($conversation))->toBe(0);
    Queue::assertNothingPushed();
})->with([ConversationStatus::Ended, ConversationStatus::Failed]);

it('ends a conversation that was never connected: no release, and its transcript is final at once', function () {
    $conversation = clientEndable(ConversationStatus::Created);

    $response = clientEnd($conversation)->assertOk();

    expect($response->json('ended_reason'))->toBe('client')
        ->and($response->json('transcript_status'))->toBe('ready');
    Queue::assertNotPushed(ReleaseProviderSession::class);
});

it('still takes the last events of the browser for a minute after it ended the conversation', function () {
    $conversation = clientEndable(ConversationStatus::Live);
    clientEnd($conversation)->assertOk();

    $this->postJson("/v1/client/conversations/{$conversation->id}/events", ['events' => [['idempotency_key' => 'last', 'type' => 'utterance', 'role' => 'user', 'text' => 'Goodbye']]], browserHeaders($this->pk, clientSecretFor($conversation)))
        ->assertOk()->assertJson(['accepted' => 1]);

    expect(ConversationEvent::withoutGlobalScopes()->where('conversation_id', $conversation->id)->count())->toBe(1);
});

it('takes the reason from the route, not from the body', function () {
    $conversation = clientEndable(ConversationStatus::Live);

    $this->postJson("/v1/client/conversations/{$conversation->id}/end", ['ended_reason' => 'provider', 'status' => 'failed'], browserHeaders($this->pk, clientSecretFor($conversation)))
        ->assertOk()->assertJsonPath('ended_reason', 'client')->assertJsonPath('status', 'ended');
});

it('answers 404 for a conversation the key\'s account does not have, and changes nothing', function () {
    $stranger = Account::factory()->create();
    $foreign = inAccount($stranger, fn () => Conversation::factory()->create(['avatar_config_id' => inAccount($stranger, fn () => AvatarConfig::factory()->create())->id, 'status' => ConversationStatus::Live]));
    $secret = app(ClientSecret::class)->mint($foreign->id, $this->account->id, now(), 1800)->token;

    $response = clientEnd($foreign, $secret)->assertNotFound();

    expect($response->json('code'))->toBe('not_found')
        ->and(Conversation::withoutGlobalScopes()->findOrFail($foreign->id)->status)->toBe(ConversationStatus::Live);
});

it('answers 403 for the secret of another conversation, and 401 without a secret', function () {
    $first = clientEndable(ConversationStatus::Live);
    $second = clientEndable(ConversationStatus::Live);

    clientEnd($first, clientSecretFor($second))->assertForbidden();
    $this->postJson("/v1/client/conversations/{$first->id}/end", [], ['X-HRA-Publishable-Key' => $this->pk, 'Origin' => 'https://app.example.com'])->assertUnauthorized();

    expect(Conversation::withoutGlobalScopes()->findOrFail($first->id)->status)->toBe(ConversationStatus::Live);
});

it('allows the browser to preflight it', function () {
    $conversation = clientEndable(ConversationStatus::Live);

    $this->call('OPTIONS', "/v1/client/conversations/{$conversation->id}/end", [], [], [], $this->transformHeadersToServerVars([
        'Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'POST',
    ]))->assertNoContent();
});
