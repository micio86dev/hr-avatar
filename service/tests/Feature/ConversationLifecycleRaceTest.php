<?php

use App\AvatarProviders\TranscriptLine;
use App\Conversations\ConversationLifecycle;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\IllegalTransition;
use App\Jobs\ReleaseProviderSession;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AvatarProviders\ScriptedProvider;

/*
 * One connection cannot interleave two requests, so a race is shown the way it is decided: two copies
 * of the same row in memory, both believing the conversation is still open, each asking to end it. The
 * database decides which one wins, with one conditional UPDATE.
 */
beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    $this->endpoint = inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
    $this->lifecycle = app(ConversationLifecycle::class);
});

function raceConversation(string $provider = 'mock', ConversationStatus $status = ConversationStatus::Live): Conversation
{
    return inAccount(test()->account, fn () => Conversation::factory()->create([
        'avatar_config_id' => test()->config->id, 'provider' => $provider, 'status' => $status, 'connecting_at' => now()->subMinutes(2), 'live_at' => now()->subMinute(), 'provider_session_ref' => 'sess-1',
    ]));
}

/**
 * @return list<string>
 */
function raceDeliveries(Conversation $conversation): array
{
    return WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('created_at')->orderBy('id')->get()->map(fn (WebhookDelivery $delivery) => $delivery->event_type->value)->all();
}

describe('two causes ending one conversation', function () {
    beforeEach(fn () => Queue::fake());

    it('lets the first writer set the reason and emit conversation.ended once', function () {
        $explicit = raceConversation();
        $sweeper = Conversation::withoutGlobalScopes()->findOrFail($explicit->id);

        $first = $this->lifecycle->end($explicit, EndedReason::Api);
        $second = $this->lifecycle->end($sweeper, EndedReason::MaxDuration);

        expect([$first, $second])->toBe([true, false])
            ->and($sweeper->status)->toBe(ConversationStatus::Ended)
            ->and($sweeper->ended_reason)->toBe(EndedReason::Api)
            ->and(raceDeliveries($explicit))->toBe(['conversation.ended']);
        Queue::assertPushed(ReleaseProviderSession::class, 1);
    });

    it('does not let the loser overwrite the reason or the time', function () {
        $explicit = raceConversation();
        $other = Conversation::withoutGlobalScopes()->findOrFail($explicit->id);
        $this->lifecycle->transition($explicit, ConversationStatus::Ended, EndedReason::Client);
        $endedAt = Conversation::withoutGlobalScopes()->find($explicit->id)->ended_at;
        $this->travel(5)->minutes();

        $this->lifecycle->transition($other, ConversationStatus::Ended, EndedReason::Provider, 'participant_left');

        $row = Conversation::withoutGlobalScopes()->findOrFail($explicit->id);
        expect($row->ended_reason)->toBe(EndedReason::Client)->and($row->provider_end_reason)->toBeNull()->and($row->ended_at->equalTo($endedAt))->toBeTrue();
    });

    it('lets a failure and an end race: whichever is second finds the conversation terminal', function (bool $failsFirst) {
        $a = raceConversation();
        $b = Conversation::withoutGlobalScopes()->findOrFail($a->id);

        if ($failsFirst) {
            $this->lifecycle->transition($a, ConversationStatus::Failed, EndedReason::ProviderError);
            $ended = $this->lifecycle->end($b, EndedReason::Api);
            expect($ended)->toBeFalse()->and($b->status)->toBe(ConversationStatus::Failed)->and($b->ended_reason)->toBe(EndedReason::ProviderError);
        } else {
            $this->lifecycle->end($a, EndedReason::Api);
            expect(fn () => $this->lifecycle->transition($b, ConversationStatus::Failed, EndedReason::ProviderError))->toThrow(IllegalTransition::class);
            expect($b->status)->toBe(ConversationStatus::Ended)->and($b->ended_reason)->toBe(EndedReason::Api);
        }

        expect(raceDeliveries($a))->toBe(['conversation.ended']);
    })->with(['failure first' => [true], 'end first' => [false]]);

    it('decides with one conditional UPDATE that names the states it may leave', function () {
        $conversation = raceConversation();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_starts_with($query->sql, 'update "conversations"')) {
                $queries[] = $query->sql;
            }
        });

        $this->lifecycle->transition($conversation, ConversationStatus::Ended, EndedReason::Api);

        $first = $queries[0];
        expect($first)->toContain('"status" in (')->and($first)->toContain('"ended_reason"')->and($first)->toContain('"ended_at"');
    });
});

describe('the work that follows a transition', function () {
    it('queues the release only once the transaction has committed', function () {
        ScriptedProvider::register();
        Http::fake(['*' => Http::response('', 204)]);
        $conversation = raceConversation('scripted');

        DB::transaction(function () use ($conversation) {
            $this->lifecycle->transition($conversation, ConversationStatus::Ended, EndedReason::Api);

            // The synchronous queue runs a job the moment it is queued: nothing may have run yet.
            expect(ScriptedProvider::$calls)->toBe([]);
        });

        expect(collect(ScriptedProvider::$calls)->pluck(0)->all())->toContain('teardown');
    });

    it('queues nothing, and records nothing, when the transaction rolls back', function () {
        ScriptedProvider::register();
        Http::fake(['*' => Http::response('', 204)]);
        $conversation = raceConversation('scripted');

        try {
            DB::transaction(function () use ($conversation) {
                $this->lifecycle->transition($conversation, ConversationStatus::Ended, EndedReason::Api);

                throw new RuntimeException('the surrounding work failed');
            });
        } catch (RuntimeException) {
        }

        expect(ScriptedProvider::$calls)->toBe([])
            ->and(raceDeliveries($conversation))->toBe([])
            ->and(Conversation::withoutGlobalScopes()->find($conversation->id)->status)->toBe(ConversationStatus::Live);
    });

    it('records the end and, for a conversation with no session, its transcript in the same transaction', function () {
        Queue::fake();
        $conversation = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => $this->config->id]));

        try {
            DB::transaction(function () use ($conversation) {
                $this->lifecycle->transition($conversation, ConversationStatus::Ended, EndedReason::Api);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $row = Conversation::withoutGlobalScopes()->findOrFail($conversation->id);
        expect($row->status)->toBe(ConversationStatus::Created)
            ->and($row->transcript_status->value)->toBe('pending')
            ->and(raceDeliveries($conversation))->toBe([]);
    });

    it('runs the reconciliation of a released session without waiting on any provider callback', function () {
        ScriptedProvider::register();
        Http::fake(['*' => Http::response('', 204)]);
        ScriptedProvider::$transcripts = [fn () => [new TranscriptLine('avatar', 'Hello', null, null)]];
        $conversation = raceConversation('scripted');

        $this->lifecycle->end($conversation, EndedReason::MaxDuration);

        // Reconciliation is queued before the teardown on the first attempt, so it never waits on it.
        expect(collect(ScriptedProvider::$calls)->pluck(0)->all())->toBe(['fetchTranscript', 'teardown'])
            ->and(Conversation::withoutGlobalScopes()->find($conversation->id)->transcript_status->value)->toBe('ready');
    });
});
