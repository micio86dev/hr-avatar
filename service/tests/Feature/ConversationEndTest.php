<?php

use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptStatus;
use App\Jobs\ReleaseProviderSession;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Queue::fake();
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['conversations:write']]);
    [, $this->readOnly] = issueKey($this->account, ['scopes' => ['conversations:read']]);
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function endable(ConversationStatus $status, array $attributes = [], ?Account $account = null): Conversation
{
    $account ??= test()->account;
    $config = $account->is(test()->account) ? test()->config : inAccount($account, fn () => AvatarConfig::factory()->create());
    $implied = match ($status) {
        ConversationStatus::Created => [],
        ConversationStatus::Connecting, ConversationStatus::Live => ['connecting_at' => now()->subMinutes(2), 'provider_session_ref' => 'sess-1'],
        ConversationStatus::Ended => ['connecting_at' => now()->subMinutes(5), 'ended_at' => now()->subMinute(), 'ended_reason' => EndedReason::Client, 'provider_session_ref' => 'sess-1'],
        ConversationStatus::Failed => ['connecting_at' => now()->subMinutes(5), 'ended_at' => now()->subMinute(), 'ended_reason' => EndedReason::ProviderError],
    };

    return inAccount($account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id, 'status' => $status, ...$implied, ...$attributes]));
}

function endCall(string $id, ?string $raw = null): TestResponse
{
    return test()->postJson("/v1/conversations/{$id}/end", [], ['Authorization' => 'Bearer '.($raw ?? test()->raw)]);
}

function endEvents(Conversation $conversation): int
{
    return WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('event_type', 'conversation.ended')->count();
}

describe('POST /v1/conversations/{id}/end', function () {
    it('ends a live conversation with the reason api and queues the release of its session', function () {
        $conversation = endable(ConversationStatus::Live, ['live_at' => now()->subMinute()]);

        $response = endCall($conversation->id)->assertOk();

        expect(array_keys($response->json()))->toBe(CONVERSATION_MEMBERS)
            ->and($response->json('id'))->toBe($conversation->id)
            ->and($response->json('status'))->toBe('ended')
            ->and($response->json('ended_reason'))->toBe('api')
            ->and($response->json('ended_at'))->not->toBeNull()
            ->and($response->json('transcript_status'))->toBe('pending');
        Queue::assertPushed(ReleaseProviderSession::class, fn (ReleaseProviderSession $job) => $job->conversationId === $conversation->id && $job->accountId === $conversation->account_id);
        expect(endEvents($conversation))->toBe(1);
    });

    it('ends a conversation that is connecting', function () {
        $conversation = endable(ConversationStatus::Connecting);

        endCall($conversation->id)->assertOk()->assertJsonPath('status', 'ended')->assertJsonPath('ended_reason', 'api');
        Queue::assertPushed(ReleaseProviderSession::class, 1);
    });

    it('answers 200 with the unchanged conversation when it is ended again, and emits nothing more', function () {
        $conversation = endable(ConversationStatus::Live);
        $first = endCall($conversation->id)->assertOk()->json();
        Queue::fake();

        $second = endCall($conversation->id)->assertOk()->json();

        expect($second)->toBe($first)->and(endEvents($conversation))->toBe(1);
        Queue::assertNotPushed(ReleaseProviderSession::class);
    });

    it('leaves a conversation ended by someone else exactly as it is', function () {
        $conversation = endable(ConversationStatus::Ended);

        $response = endCall($conversation->id)->assertOk();

        expect($response->json('ended_reason'))->toBe('client')
            ->and(endEvents($conversation))->toBe(0);
    });

    it('leaves a failed conversation as it is: failed, with its own reason', function () {
        $conversation = endable(ConversationStatus::Failed);

        endCall($conversation->id)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('ended_reason', 'provider_error');
        expect(endEvents($conversation))->toBe(0);
    });

    it('ends a conversation that was never connected: no release is queued and its transcript is final at once', function () {
        $conversation = endable(ConversationStatus::Created);

        $response = endCall($conversation->id)->assertOk();

        expect($response->json('status'))->toBe('ended')
            ->and($response->json('ended_reason'))->toBe('api')
            ->and($response->json('transcript_status'))->toBe('ready');
        Queue::assertNotPushed(ReleaseProviderSession::class);
        expect(Conversation::withoutGlobalScopes()->find($conversation->id)->transcript_status)->toBe(TranscriptStatus::Ready)
            ->and(WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('event_type', 'transcript.ready')->count())->toBe(1);
    });

    it('answers 404 for a conversation of another account, and changes nothing', function () {
        $foreign = endable(ConversationStatus::Live, account: Account::factory()->create());

        $response = endCall($foreign->id)->assertNotFound();

        expect($response->json('code'))->toBe('not_found')
            ->and(Conversation::withoutGlobalScopes()->find($foreign->id)->status)->toBe(ConversationStatus::Live);
        Queue::assertNothingPushed();
    });

    it('answers 404 for a conversation that does not exist', function () {
        expect(endCall('conv_missing')->assertNotFound()->json('code'))->toBe('not_found');
    });

    it('needs the conversations:write scope', function () {
        $conversation = endable(ConversationStatus::Live);

        $response = endCall($conversation->id, $this->readOnly)->assertForbidden();

        expect($response->json('code'))->toBe('insufficient_scope')
            ->and(Conversation::withoutGlobalScopes()->find($conversation->id)->status)->toBe(ConversationStatus::Live);
    });

    it('needs a key', function () {
        $conversation = endable(ConversationStatus::Live);

        $this->postJson("/v1/conversations/{$conversation->id}/end")->assertUnauthorized();
    });

    it('accepts no body that changes what happens: the reason is the key\'s, not the caller\'s', function () {
        $conversation = endable(ConversationStatus::Live);

        $this->postJson("/v1/conversations/{$conversation->id}/end", ['ended_reason' => 'provider', 'status' => 'failed'], ['Authorization' => 'Bearer '.$this->raw])
            ->assertOk()->assertJsonPath('status', 'ended')->assertJsonPath('ended_reason', 'api');
    });
});
