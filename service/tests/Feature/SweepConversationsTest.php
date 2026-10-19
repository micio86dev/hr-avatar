<?php

use App\Conversations\ConversationLifecycle;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptStatus;
use App\Jobs\ReleaseProviderSession;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-10-19 12:00:00');
    $this->account = Account::factory()->create();
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    $this->endpoint = inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
});

/**
 * A conversation that was connected `$connectedSecondsAgo` seconds ago and has run since.
 *
 * @param  array<string, mixed>  $attributes
 */
function sweepable(ConversationStatus $status, int $connectedSecondsAgo, array $attributes = [], ?Account $account = null): Conversation
{
    $account ??= test()->account;
    $config = $account->is(test()->account) ? test()->config : inAccount($account, fn () => AvatarConfig::factory()->create());

    return inAccount($account, fn () => Conversation::factory()->create([
        'avatar_config_id' => $config->id, 'status' => $status, 'max_duration_seconds' => 1800,
        'connecting_at' => now()->subSeconds($connectedSecondsAgo), 'provider_session_ref' => 'sess-1',
        ...($status === ConversationStatus::Live ? ['live_at' => now()->subSeconds($connectedSecondsAgo - 5)] : []),
        ...$attributes,
    ]));
}

/**
 * A conversation that was created `$createdSecondsAgo` seconds ago and never connected.
 *
 * @param  array<string, mixed>  $attributes
 */
function unconnected(int $createdSecondsAgo, array $attributes = [], ?Account $account = null): Conversation
{
    $account ??= test()->account;
    $config = $account->is(test()->account) ? test()->config : inAccount($account, fn () => AvatarConfig::factory()->create());

    return inAccount($account, fn () => Conversation::factory()->create([
        'avatar_config_id' => $config->id, 'created_at' => now()->subSeconds($createdSecondsAgo), 'updated_at' => now()->subSeconds($createdSecondsAgo), ...$attributes,
    ]));
}

function sweep(): int
{
    return Artisan::call('hra:conversations:sweep');
}

function swept(Conversation $conversation): Conversation
{
    return Conversation::withoutGlobalScopes()->findOrFail($conversation->id);
}

/**
 * @return list<string>
 */
function sweepEvents(Conversation $conversation): array
{
    return WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('created_at')->orderBy('id')->get()
        ->map(fn (WebhookDelivery $delivery) => $delivery->event_type->value)->all();
}

describe('a conversation that overran its maximum duration', function () {
    it('is ended with the reason max_duration and its session released', function (ConversationStatus $status) {
        $conversation = sweepable($status, 1900);

        expect(sweep())->toBe(0);

        $row = swept($conversation);
        expect($row->status)->toBe(ConversationStatus::Ended)
            ->and($row->ended_reason)->toBe(EndedReason::MaxDuration)
            ->and($row->ended_at?->toIso8601String())->toBe(now()->toIso8601String())
            ->and(sweepEvents($conversation))->toBe(['conversation.ended']);
        Queue::assertPushed(ReleaseProviderSession::class, fn (ReleaseProviderSession $job) => $job->conversationId === $conversation->id && $job->accountId === $conversation->account_id);
    })->with([ConversationStatus::Live, ConversationStatus::Connecting]);

    it('is ended only once its own maximum plus thirty seconds has passed', function (int $secondsAgo, bool $ended) {
        $conversation = sweepable(ConversationStatus::Live, $secondsAgo);

        sweep();

        expect(swept($conversation)->status)->toBe($ended ? ConversationStatus::Ended : ConversationStatus::Live);
    })->with([
        'connected 100 s ago' => [100, false],
        'one second short of the maximum plus the margin' => [1829, false],
        'exactly the maximum plus the margin' => [1830, false],
        'one second past it' => [1831, true],
    ]);

    it('reads the maximum from each conversation', function () {
        $short = sweepable(ConversationStatus::Live, 700, ['max_duration_seconds' => 600]);
        $long = sweepable(ConversationStatus::Live, 700, ['max_duration_seconds' => 1800]);

        sweep();

        expect(swept($short)->status)->toBe(ConversationStatus::Ended)->and(swept($long)->status)->toBe(ConversationStatus::Live);
    });

    it('leaves a conversation within its limits exactly as it is', function () {
        $conversation = sweepable(ConversationStatus::Live, 100);
        $before = swept($conversation)->getAttributes();

        sweep();

        expect(swept($conversation)->getAttributes())->toBe($before)->and(sweepEvents($conversation))->toBe([]);
        Queue::assertNothingPushed();
    });

    it('ends a conversation that has no session as well, and finishes its transcript at once', function () {
        $conversation = sweepable(ConversationStatus::Connecting, 2000, ['provider_session_ref' => null]);

        sweep();

        expect(swept($conversation)->status)->toBe(ConversationStatus::Ended)
            ->and(swept($conversation)->transcript_status)->toBe(TranscriptStatus::Ready)
            ->and(sweepEvents($conversation))->toBe(['conversation.ended', 'transcript.ready']);
        Queue::assertNotPushed(ReleaseProviderSession::class);
    });
});

describe('a conversation that was never connected', function () {
    it('is ended with the reason expired once its connect window has passed, and has no session to release', function () {
        $conversation = unconnected(901);

        sweep();

        $row = swept($conversation);
        expect($row->status)->toBe(ConversationStatus::Ended)
            ->and($row->ended_reason)->toBe(EndedReason::Expired)
            ->and($row->transcript_status)->toBe(TranscriptStatus::Ready)
            ->and(sweepEvents($conversation))->toBe(['conversation.ended', 'transcript.ready']);
        Queue::assertNotPushed(ReleaseProviderSession::class);
    });

    it('is left alone inside the connect window, the boundary included', function (int $createdSecondsAgo) {
        $conversation = unconnected($createdSecondsAgo);
        $before = swept($conversation)->getAttributes();

        sweep();

        expect(swept($conversation)->getAttributes())->toBe($before);
    })->with([899, 900]);

    it('does not count a conversation that is already connecting as never connected', function () {
        $conversation = sweepable(ConversationStatus::Connecting, 100, ['created_at' => now()->subSeconds(5000)]);

        sweep();

        expect(swept($conversation)->status)->toBe(ConversationStatus::Connecting);
    });
});

describe('a conversation that is already over', function () {
    it('is not touched', function (ConversationStatus $status) {
        $conversation = sweepable($status, 9000, ['ended_at' => now()->subSeconds(8000), 'ended_reason' => $status === ConversationStatus::Ended ? EndedReason::Client : EndedReason::ProviderError, 'created_at' => now()->subSeconds(9500)]);
        $before = swept($conversation)->getAttributes();

        sweep();

        expect(swept($conversation)->getAttributes())->toBe($before)->and(sweepEvents($conversation))->toBe([]);
        Queue::assertNothingPushed();
    })->with([ConversationStatus::Ended, ConversationStatus::Failed]);
});

describe('two sweepers', function () {
    it('produce one conversation.ended between them when they run one after the other', function () {
        $conversation = sweepable(ConversationStatus::Live, 2000);

        sweep();
        sweep();

        expect(sweepEvents($conversation))->toBe(['conversation.ended']);
        Queue::assertPushed(ReleaseProviderSession::class, 1);
    });

    it('leave the conversation to whoever ended it first when one of them was already reading it', function () {
        $conversation = sweepable(ConversationStatus::Live, 2000);
        $ended = false;
        Conversation::retrieved(function (Conversation $model) use (&$ended, $conversation) {
            if ($ended || $model->id !== $conversation->id) {
                return;
            }

            // Another process ends it between the sweeper's read and its write.
            $ended = true;
            app(ConversationLifecycle::class)->end(Conversation::withoutGlobalScopes()->findOrFail($conversation->id), EndedReason::Api);
        });

        expect(sweep())->toBe(0);

        expect(swept($conversation)->ended_reason)->toBe(EndedReason::Api)
            ->and(sweepEvents($conversation))->toBe(['conversation.ended']);
        Queue::assertPushed(ReleaseProviderSession::class, 1);
    });
});

describe('every account', function () {
    it('has its own conversations swept, and webhooks go to its own endpoints', function () {
        $other = Account::factory()->create();
        $otherEndpoint = inAccount($other, fn () => WebhookEndpoint::factory()->create());
        $mine = sweepable(ConversationStatus::Live, 2000);
        $theirs = sweepable(ConversationStatus::Live, 2000, account: $other);
        $quiet = Account::factory()->create();
        $quietOne = sweepable(ConversationStatus::Live, 100, account: $quiet);

        sweep();

        $byEndpoint = WebhookDelivery::withoutGlobalScopes()->get()->groupBy('webhook_endpoint_id')->map(fn ($rows) => $rows->pluck('conversation_id')->all());
        expect(swept($mine)->status)->toBe(ConversationStatus::Ended)
            ->and(swept($theirs)->status)->toBe(ConversationStatus::Ended)
            ->and(swept($quietOne)->status)->toBe(ConversationStatus::Live)
            ->and($byEndpoint[$this->endpoint->id])->toBe([$mine->id])
            ->and($byEndpoint[$otherEndpoint->id])->toBe([$theirs->id]);
    });

    it('never reads a conversation without the account it belongs to', function () {
        $other = Account::factory()->create();
        sweepable(ConversationStatus::Live, 2000);
        sweepable(ConversationStatus::Live, 2000, account: $other);
        unconnected(1000);
        $selects = [];
        DB::listen(function ($query) use (&$selects) {
            if (preg_match('/^select .* from "conversations"/', $query->sql) === 1) {
                $selects[] = $query->sql;
            }
        });

        sweep();

        $unscoped = array_values(array_filter($selects, fn (string $sql) => ! str_contains($sql, '"account_id" = ')));
        expect($selects)->not->toBe([])->and($unscoped)->toBe([]);
    });
});

describe('a big sweep', function () {
    it('goes through every overdue conversation, in chunks', function () {
        $ids = collect(range(1, 103))->map(fn () => sweepable(ConversationStatus::Live, 2000)->id);

        expect(sweep())->toBe(0);

        expect(Conversation::withoutGlobalScopes()->whereIn('id', $ids)->where('status', 'ended')->count())->toBe(103);
    });
});

describe('a conversation that cannot be ended', function () {
    beforeEach(function () {
        // A trigger that refuses the update of one row, as a stand-in for any failure while ending it.
        DB::unprepared("CREATE FUNCTION refuse_one_conversation() RETURNS trigger AS \$\$ BEGIN IF NEW.id = 'conv_refused' THEN RAISE EXCEPTION 'refused with a secret in it'; END IF; RETURN NEW; END \$\$ LANGUAGE plpgsql");
        DB::unprepared('CREATE TRIGGER refuse_one_conversation BEFORE UPDATE ON conversations FOR EACH ROW EXECUTE FUNCTION refuse_one_conversation()');
    });

    it('does not stop the others: they are ended, the failure is logged by kind and the command reports it', function () {
        $logs = captureLogs();
        $context = null;
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$context) {
            if ($event->message === 'Conversation sweep failed') {
                $context = $event->context;
            }
        });
        $refused = sweepable(ConversationStatus::Live, 2000, ['id' => 'conv_refused']);
        $fine = sweepable(ConversationStatus::Live, 2000);
        $also = unconnected(1000);

        expect(sweep())->toBe(1);

        expect(swept($refused)->status)->toBe(ConversationStatus::Live)
            ->and(swept($fine)->status)->toBe(ConversationStatus::Ended)
            ->and(swept($also)->status)->toBe(ConversationStatus::Ended)
            ->and($context)->toBe(['conversation_id' => 'conv_refused', 'exception' => QueryException::class])
            ->and(collect($logs)->contains(fn (string $line) => str_contains($line, 'secret in it')))->toBeFalse();
    });
});

describe('the command', function () {
    it('says how many conversations it ended', function () {
        sweepable(ConversationStatus::Live, 2000);
        unconnected(1000);

        Artisan::call('hra:conversations:sweep');

        expect(Artisan::output())->toContain('Ended 2 conversation(s).');
    });

    it('does nothing, and says so, when there is nothing to end', function () {
        sweepable(ConversationStatus::Live, 100);

        expect(sweep())->toBe(0)->and(Artisan::output())->toContain('Ended 0 conversation(s).');
    });

    it('is scheduled every minute and never overlaps itself', function () {
        $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event) => str_contains($event->command ?? '', 'hra:conversations:sweep'));

        expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue();
    });
});
