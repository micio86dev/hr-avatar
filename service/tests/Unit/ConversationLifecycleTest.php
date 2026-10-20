<?php

use App\Conversations\ConversationLifecycle;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\IllegalTransition;
use App\Conversations\TranscriptStatus;
use App\Http\ErrorCode;
use App\Jobs\ReleaseProviderSession;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-10-19 12:00:00');
    $this->account = Account::factory()->create();
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    $this->endpoint = inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
    $this->lifecycle = app(ConversationLifecycle::class);
});

/**
 * A conversation in the given state, holding what that state implies.
 *
 * @param  array<string, mixed>  $attributes
 */
function lcConversation(ConversationStatus $status = ConversationStatus::Created, array $attributes = []): Conversation
{
    $implied = match ($status) {
        ConversationStatus::Created => [],
        ConversationStatus::Connecting => ['connecting_at' => now()->subMinute(), 'provider_session_ref' => 'sess-1'],
        ConversationStatus::Live => ['connecting_at' => now()->subMinutes(2), 'live_at' => now()->subMinute(), 'provider_session_ref' => 'sess-1'],
        ConversationStatus::Ended => ['connecting_at' => now()->subMinutes(5), 'live_at' => now()->subMinutes(4), 'ended_at' => now()->subMinute(), 'ended_reason' => EndedReason::Client, 'provider_session_ref' => 'sess-1'],
        ConversationStatus::Failed => ['connecting_at' => now()->subMinutes(5), 'ended_at' => now()->subMinute(), 'ended_reason' => EndedReason::ProviderError],
    };

    return inAccount(test()->account, fn () => Conversation::factory()->create([
        'avatar_config_id' => test()->config->id, 'status' => $status, ...$implied, ...$attributes,
    ]));
}

/**
 * @return list<string> the event types recorded for the conversation, in order
 */
function lcDeliveries(Conversation $conversation): array
{
    return WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('created_at')->orderBy('id')->get()->map(fn (WebhookDelivery $delivery) => $delivery->event_type->value)->all();
}

function lcFresh(Conversation $conversation): Conversation
{
    return Conversation::withoutGlobalScopes()->findOrFail($conversation->id);
}

describe('the legal transitions', function () {
    it('moves a conversation along each edge of the state machine', function (ConversationStatus $from, ConversationStatus $to, ?EndedReason $reason) {
        $conversation = lcConversation($from);

        $changed = $this->lifecycle->transition($conversation, $to, $reason);

        expect($changed)->toBeTrue()
            ->and(lcFresh($conversation)->status)->toBe($to)
            ->and($conversation->status)->toBe($to);
    })->with([
        'created to connecting' => [ConversationStatus::Created, ConversationStatus::Connecting, null],
        'created to ended' => [ConversationStatus::Created, ConversationStatus::Ended, EndedReason::Api],
        'connecting to live' => [ConversationStatus::Connecting, ConversationStatus::Live, null],
        'connecting to ended' => [ConversationStatus::Connecting, ConversationStatus::Ended, EndedReason::Client],
        'connecting to failed' => [ConversationStatus::Connecting, ConversationStatus::Failed, EndedReason::ProviderError],
        'live to ended' => [ConversationStatus::Live, ConversationStatus::Ended, EndedReason::MaxDuration],
        'live to failed' => [ConversationStatus::Live, ConversationStatus::Failed, EndedReason::ProviderCapacity],
    ]);

    it('stamps the time of the state it reaches and nothing else', function () {
        $connecting = lcConversation();
        $this->lifecycle->transition($connecting, ConversationStatus::Connecting);
        $live = lcConversation(ConversationStatus::Connecting);
        $this->lifecycle->transition($live, ConversationStatus::Live);
        $ended = lcConversation(ConversationStatus::Live);
        $this->lifecycle->transition($ended, ConversationStatus::Ended, EndedReason::Provider, 'participant_left');

        expect(lcFresh($connecting)->connecting_at?->toIso8601String())->toBe(now()->toIso8601String())
            ->and(lcFresh($connecting)->live_at)->toBeNull()
            ->and(lcFresh($connecting)->ended_at)->toBeNull()
            ->and(lcFresh($live)->live_at?->toIso8601String())->toBe(now()->toIso8601String())
            ->and(lcFresh($live)->connecting_at?->toIso8601String())->toBe(now()->subMinute()->toIso8601String())
            ->and(lcFresh($live)->ended_at)->toBeNull()
            ->and(lcFresh($ended)->ended_at?->toIso8601String())->toBe(now()->toIso8601String())
            ->and(lcFresh($ended)->live_at?->toIso8601String())->toBe(now()->subMinute()->toIso8601String());
    });

    it('records why a conversation ended, and the provider\'s own reason beside it', function () {
        $conversation = lcConversation(ConversationStatus::Live);

        $this->lifecycle->transition($conversation, ConversationStatus::Ended, EndedReason::Provider, 'participant_left');

        $fresh = lcFresh($conversation);
        expect($fresh->ended_reason)->toBe(EndedReason::Provider)->and($fresh->provider_end_reason)->toBe('participant_left');
    });

    it('records a failure with its reason and the time it happened', function (EndedReason $reason) {
        $conversation = lcConversation(ConversationStatus::Connecting);

        $this->lifecycle->transition($conversation, ConversationStatus::Failed, $reason);

        $fresh = lcFresh($conversation);
        expect($fresh->status)->toBe(ConversationStatus::Failed)
            ->and($fresh->ended_reason)->toBe($reason)
            ->and($fresh->ended_at?->toIso8601String())->toBe(now()->toIso8601String());
    })->with([EndedReason::ProviderError, EndedReason::ProviderCapacity]);

    it('refreshes the model it was given, so the caller reads the state that now holds', function () {
        $conversation = lcConversation(ConversationStatus::Live);

        $this->lifecycle->transition($conversation, ConversationStatus::Ended, EndedReason::Api);

        expect($conversation->status)->toBe(ConversationStatus::Ended)->and($conversation->ended_reason)->toBe(EndedReason::Api)->and($conversation->ended_at)->not->toBeNull();
    });
});

describe('the illegal transitions', function () {
    it('refuses every transition the machine does not have, as an invalid state, and changes nothing', function (ConversationStatus $from, ConversationStatus $to) {
        $conversation = lcConversation($from);
        $before = lcFresh($conversation)->getAttributes();

        try {
            $this->lifecycle->transition($conversation, $to, match ($to) {
                ConversationStatus::Ended => EndedReason::Api,
                ConversationStatus::Failed => EndedReason::ProviderError,
                default => null,
            });
            $this->fail('The transition was not refused.');
        } catch (IllegalTransition $refused) {
            expect($refused->errorCode)->toBe(ErrorCode::InvalidState)->and($refused->errorCode->status())->toBe(409);
        }

        expect(lcFresh($conversation)->getAttributes())->toBe($before)
            ->and(lcDeliveries($conversation))->toBe([]);
        Queue::assertNothingPushed();
    })->with([
        'created to live' => [ConversationStatus::Created, ConversationStatus::Live],
        'created to failed' => [ConversationStatus::Created, ConversationStatus::Failed],
        'live to connecting' => [ConversationStatus::Live, ConversationStatus::Connecting],
        'live to created' => [ConversationStatus::Live, ConversationStatus::Created],
        'connecting to created' => [ConversationStatus::Connecting, ConversationStatus::Created],
        'ended to connecting' => [ConversationStatus::Ended, ConversationStatus::Connecting],
        'ended to live' => [ConversationStatus::Ended, ConversationStatus::Live],
        'ended to failed' => [ConversationStatus::Ended, ConversationStatus::Failed],
        'ended to created' => [ConversationStatus::Ended, ConversationStatus::Created],
        'failed to connecting' => [ConversationStatus::Failed, ConversationStatus::Connecting],
        'failed to live' => [ConversationStatus::Failed, ConversationStatus::Live],
        'failed to ended' => [ConversationStatus::Failed, ConversationStatus::Ended],
        'failed to created' => [ConversationStatus::Failed, ConversationStatus::Created],
    ]);

    it('holds a terminal conversation as it is, whoever comes second', function (ConversationStatus $terminal, EndedReason $first, EndedReason $second) {
        $conversation = lcConversation($terminal, ['ended_reason' => $first, 'provider_end_reason' => null]);
        $before = lcFresh($conversation)->getAttributes();

        $changed = $this->lifecycle->transition($conversation, $terminal, $second, $terminal === ConversationStatus::Ended ? 'late reason' : null);

        expect($changed)->toBeFalse()->and(lcFresh($conversation)->getAttributes())->toBe($before);
    })->with([
        'ended by the client, then the provider' => [ConversationStatus::Ended, EndedReason::Client, EndedReason::Provider],
        'ended by the api, then the sweeper' => [ConversationStatus::Ended, EndedReason::Api, EndedReason::MaxDuration],
        'failed with an error, then a capacity failure' => [ConversationStatus::Failed, EndedReason::ProviderError, EndedReason::ProviderCapacity],
    ]);
});

describe('a transition that already holds', function () {
    it('is an idempotent no-op that emits nothing', function (ConversationStatus $state) {
        $conversation = lcConversation($state);
        $before = lcFresh($conversation)->getAttributes();
        $deliveries = lcDeliveries($conversation);

        $changed = $this->lifecycle->transition($conversation, $state, in_array($state, [ConversationStatus::Ended], true) ? EndedReason::Api : null);

        expect($changed)->toBeFalse()
            ->and(lcFresh($conversation)->getAttributes())->toBe($before)
            ->and(lcDeliveries($conversation))->toBe($deliveries);
        Queue::assertNothingPushed();
    })->with([ConversationStatus::Connecting, ConversationStatus::Live, ConversationStatus::Ended]);

    it('does not emit conversation.live a second time', function () {
        $conversation = lcConversation(ConversationStatus::Connecting);

        $first = $this->lifecycle->transition($conversation, ConversationStatus::Live);
        $second = $this->lifecycle->transition($conversation, ConversationStatus::Live);

        expect([$first, $second])->toBe([true, false])->and(lcDeliveries($conversation))->toBe(['conversation.live']);
    });
});

describe('the arguments', function () {
    it('refuses a transition whose reason does not fit its target', function (ConversationStatus $from, ConversationStatus $to, ?EndedReason $reason) {
        $conversation = lcConversation($from);

        expect(fn () => $this->lifecycle->transition($conversation, $to, $reason))->toThrow(InvalidArgumentException::class)
            ->and(lcFresh($conversation)->status)->toBe($from);
    })->with([
        'ended without a reason' => [ConversationStatus::Live, ConversationStatus::Ended, null],
        'ended with a failure reason' => [ConversationStatus::Live, ConversationStatus::Ended, EndedReason::ProviderError],
        'failed without a reason' => [ConversationStatus::Connecting, ConversationStatus::Failed, null],
        'failed with an ordinary reason' => [ConversationStatus::Connecting, ConversationStatus::Failed, EndedReason::Client],
        'live with a reason' => [ConversationStatus::Connecting, ConversationStatus::Live, EndedReason::Api],
        'connecting with a reason' => [ConversationStatus::Created, ConversationStatus::Connecting, EndedReason::Api],
    ]);

    it('takes a provider reason only for a conversation that ended', function () {
        $conversation = lcConversation(ConversationStatus::Connecting);

        expect(fn () => $this->lifecycle->transition($conversation, ConversationStatus::Live, null, 'reason'))->toThrow(InvalidArgumentException::class);
    });
});

describe('ending', function () {
    it('ends an open conversation with the reason it was given', function (ConversationStatus $from) {
        $conversation = lcConversation($from);

        $changed = $this->lifecycle->end($conversation, EndedReason::Api);

        expect($changed)->toBeTrue()->and(lcFresh($conversation)->status)->toBe(ConversationStatus::Ended)->and(lcFresh($conversation)->ended_reason)->toBe(EndedReason::Api);
    })->with([ConversationStatus::Created, ConversationStatus::Connecting, ConversationStatus::Live]);

    it('leaves a terminal conversation exactly as it is, a failed one included', function (ConversationStatus $terminal) {
        $conversation = lcConversation($terminal);
        $before = lcFresh($conversation)->getAttributes();

        $changed = $this->lifecycle->end($conversation, EndedReason::Client);

        expect($changed)->toBeFalse()->and(lcFresh($conversation)->getAttributes())->toBe($before)->and(lcDeliveries($conversation))->toBe([]);
        Queue::assertNothingPushed();
    })->with([ConversationStatus::Ended, ConversationStatus::Failed]);

    it('passes the provider\'s reason on', function () {
        $conversation = lcConversation(ConversationStatus::Live);

        $this->lifecycle->end($conversation, EndedReason::Provider, 'IDLE_TIMEOUT');

        expect(lcFresh($conversation)->provider_end_reason)->toBe('IDLE_TIMEOUT');
    });
});

describe('what a transition does besides changing the row', function () {
    it('records conversation.live when a conversation becomes live', function () {
        $conversation = lcConversation(ConversationStatus::Connecting);

        $this->lifecycle->transition($conversation, ConversationStatus::Live);

        expect(lcDeliveries($conversation))->toBe(['conversation.live']);
        Queue::assertNotPushed(ReleaseProviderSession::class);
    });

    it('records conversation.ended, and queues the release of the provider session after the configured delay', function () {
        config()->set('hr-avatar.release_delay_seconds', 45);
        $conversation = lcConversation(ConversationStatus::Live);

        $this->lifecycle->transition($conversation, ConversationStatus::Ended, EndedReason::Api);

        expect(lcDeliveries($conversation))->toBe(['conversation.ended'])
            ->and(lcFresh($conversation)->transcript_status)->toBe(TranscriptStatus::Pending);
        Queue::assertPushed(ReleaseProviderSession::class, fn (ReleaseProviderSession $job) => $job->accountId === $conversation->account_id
            && $job->conversationId === $conversation->id
            && $job->delay === 45);
        Queue::assertPushed(ReleaseProviderSession::class, 1);
    });

    it('releases a session at the default delay of twenty seconds', function () {
        $conversation = lcConversation(ConversationStatus::Connecting);

        $this->lifecycle->transition($conversation, ConversationStatus::Ended, EndedReason::Client);

        Queue::assertPushed(ReleaseProviderSession::class, fn (ReleaseProviderSession $job) => $job->delay === 20);
    });

    it('finalizes the transcript at once, as empty and ready, when there is no provider session to release', function (ConversationStatus $from, ConversationStatus $to, EndedReason $reason) {
        $conversation = lcConversation($from, ['provider_session_ref' => null]);

        $this->lifecycle->transition($conversation, $to, $reason);

        expect(lcFresh($conversation)->transcript_status)->toBe(TranscriptStatus::Ready)
            ->and(lcDeliveries($conversation))->toBe(['conversation.ended', 'transcript.ready']);
        Queue::assertNotPushed(ReleaseProviderSession::class);
    })->with([
        'ended before connecting' => [ConversationStatus::Created, ConversationStatus::Ended, EndedReason::Api],
        'expired before connecting' => [ConversationStatus::Created, ConversationStatus::Ended, EndedReason::Expired],
        'failed at connect' => [ConversationStatus::Connecting, ConversationStatus::Failed, EndedReason::ProviderError],
    ]);

    it('reports a failure as conversation.ended, with the status failed', function () {
        $conversation = lcConversation(ConversationStatus::Connecting, ['provider_session_ref' => null]);

        $this->lifecycle->transition($conversation, ConversationStatus::Failed, EndedReason::ProviderCapacity);

        $delivery = WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('event_type', 'conversation.ended')->firstOrFail();
        expect(json_decode($delivery->payload, true)['data']['conversation'])->toMatchArray(['status' => 'failed', 'ended_reason' => 'provider_capacity']);
    });

    it('queues the release of a session that a failure leaves behind', function () {
        $conversation = lcConversation(ConversationStatus::Live);

        $this->lifecycle->transition($conversation, ConversationStatus::Failed, EndedReason::ProviderError);

        Queue::assertPushed(ReleaseProviderSession::class, 1);
        expect(lcFresh($conversation)->transcript_status)->toBe(TranscriptStatus::Pending);
    });

    it('does nothing of this for a transition that did not happen', function () {
        $conversation = lcConversation(ConversationStatus::Live);
        $this->lifecycle->transition($conversation, ConversationStatus::Ended, EndedReason::Api);
        $deliveries = lcDeliveries($conversation);

        $this->lifecycle->transition($conversation, ConversationStatus::Ended, EndedReason::MaxDuration);

        expect(lcDeliveries($conversation))->toBe($deliveries);
        Queue::assertPushed(ReleaseProviderSession::class, 1);
    });
});
