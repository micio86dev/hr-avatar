<?php

use App\AvatarProviders\Ingestion;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\SessionStatus;
use App\Conversations\ConversationLifecycle;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptStatus;
use App\Jobs\ProcessProviderEvent;
use App\Jobs\ReleaseProviderSession;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ProviderCredential;
use App\Models\ProviderWebhookReceipt;
use App\Models\WebhookEndpoint;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AvatarProviders\ScriptedProvider;

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 12:00:00');
    ScriptedProvider::register('polled', 'liveavatar');
    ScriptedProvider::$ingestion = Ingestion::Poll;
    $this->account = Account::factory()->create();
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
});

/**
 * A conversation on a polled provider (a scripted one that needs no credential), live and holding a session.
 *
 * @param  array<string, mixed>  $attributes
 */
function polled(array $attributes = [], ?Account $account = null, string $provider = 'polled'): Conversation
{
    $account ??= test()->account;
    $config = $account->is(test()->account) ? test()->config : inAccount($account, fn () => AvatarConfig::factory()->create());

    return inAccount($account, fn () => Conversation::factory()->create([
        'avatar_config_id' => $config->id, 'provider' => $provider, 'status' => ConversationStatus::Live,
        'connecting_at' => now()->subMinutes(2), 'live_at' => now()->subMinute(), 'provider_session_ref' => 'sess-'.Str::lower(Str::random(8)), ...$attributes,
    ]));
}

function poll(): int
{
    return Artisan::call('hra:conversations:poll');
}

function pollFresh(Conversation $conversation): Conversation
{
    return Conversation::withoutGlobalScopes()->findOrFail($conversation->id);
}

function sessionCalls(): int
{
    return count(array_filter(ScriptedProvider::$calls, fn (array $call) => $call[0] === 'fetchSession'));
}

/**
 * Runs the processing jobs the poll queued, the way a worker would.
 */
function runQueuedProcessing(): void
{
    foreach (Queue::pushed(ProcessProviderEvent::class) as $job) {
        app()->call([$job, 'handle']);
    }
}

describe('a session the provider reports as ended', function () {
    beforeEach(fn () => Queue::fake());

    it('is stored as an ended event keyed by the session, then ends the conversation with the provider\'s reason', function () {
        $conversation = polled(['provider_session_ref' => 'sess-1']);
        ScriptedProvider::$sessions = [new SessionStatus(true, 'IDLE_TIMEOUT')];

        expect(poll())->toBe(0);
        $receipt = ProviderWebhookReceipt::withoutGlobalScopes()->sole();
        expect($receipt->idempotency_key)->toBe('liveavatar:sess-1:ended')
            ->and($receipt->provider)->toBe('polled')
            ->and($receipt->provider_ref)->toBe('sess-1')
            ->and($receipt->conversation_id)->toBe($conversation->id)
            ->and($receipt->provider_end_reason)->toBe('IDLE_TIMEOUT');
        Queue::assertPushed(ProcessProviderEvent::class, fn (ProcessProviderEvent $job) => $job->receiptId === $receipt->id && $job->accountId === $conversation->account_id);

        runQueuedProcessing();
        $row = pollFresh($conversation);
        expect($row->status)->toBe(ConversationStatus::Ended)
            ->and($row->ended_reason)->toBe(EndedReason::Provider)
            ->and($row->provider_end_reason)->toBe('IDLE_TIMEOUT');
        Queue::assertPushed(ReleaseProviderSession::class, fn (ReleaseProviderSession $job) => $job->conversationId === $conversation->id);
    });

    it('is asked about with the provider\'s credential, resolved for the conversation', function () {
        polled(['provider_session_ref' => 'sess-1']);

        poll();

        expect(ScriptedProvider::$calls)->toBe([['fetchSession', 'sess-1', null, 'none']]);
    });

    it('is not stored a second time on the next run, whether or not the first has been processed', function () {
        $conversation = polled(['provider_session_ref' => 'sess-1']);
        ScriptedProvider::$sessions = [new SessionStatus(true, 'IDLE_TIMEOUT'), new SessionStatus(true, 'IDLE_TIMEOUT'), new SessionStatus(true, 'IDLE_TIMEOUT')];

        poll();
        poll();
        expect(ProviderWebhookReceipt::withoutGlobalScopes()->count())->toBe(1);
        Queue::assertPushed(ProcessProviderEvent::class, 1);

        runQueuedProcessing();
        poll();

        expect(ProviderWebhookReceipt::withoutGlobalScopes()->count())->toBe(1)
            ->and(sessionCalls())->toBe(2)
            ->and(pollFresh($conversation)->status)->toBe(ConversationStatus::Ended);
    });

    it('is ended once whichever of the poller and the explicit end comes second', function () {
        $conversation = polled(['provider_session_ref' => 'sess-1']);
        ScriptedProvider::$sessions = [new SessionStatus(true, 'USER_CLOSED')];
        poll();
        // The host ends it before the queued processing runs.
        app(ConversationLifecycle::class)->end(pollFresh($conversation), EndedReason::Api);

        runQueuedProcessing();

        expect(pollFresh($conversation)->ended_reason)->toBe(EndedReason::Api);
    });

    it('uses unknown when the provider gave no reason', function () {
        $conversation = polled(['provider_session_ref' => 'sess-1']);
        ScriptedProvider::$sessions = [new SessionStatus(true, null)];

        poll();
        runQueuedProcessing();

        expect(pollFresh($conversation)->provider_end_reason)->toBe('unknown');
    });
});

describe('a session that is still going', function () {
    beforeEach(fn () => Queue::fake());

    it('leaves the conversation alone and stores nothing', function () {
        $conversation = polled();
        $before = pollFresh($conversation)->getAttributes();

        expect(poll())->toBe(0);

        expect(pollFresh($conversation)->getAttributes())->toBe($before)->and(ProviderWebhookReceipt::withoutGlobalScopes()->count())->toBe(0)->and(sessionCalls())->toBe(1);
        Queue::assertNothingPushed();
    });
});

describe('a provider that fails', function () {
    beforeEach(fn () => Queue::fake());

    it('is not an end: nothing changes, the status class is logged and no credential is', function () {
        $logs = captureLogs();
        $context = null;
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$context) {
            if ($event->message === 'Provider session lookup failed') {
                $context = $event->context;
            }
        });
        $conversation = polled();
        $before = pollFresh($conversation)->getAttributes();
        ScriptedProvider::$sessions = [fn () => throw ProviderException::fromStatus(502)];

        expect(poll())->toBe(0);

        expect(pollFresh($conversation)->getAttributes())->toBe($before)
            ->and(ProviderWebhookReceipt::withoutGlobalScopes()->count())->toBe(0)
            ->and($context)->toBe(['conversation_id' => $conversation->id, 'status_class' => '5xx'])
            ->and(collect($logs)->contains(fn (string $line) => str_contains($line, 'sess-')))->toBeFalse();
    });

    it('does not stop the other conversations from being asked', function () {
        $first = polled();
        $second = polled();
        ScriptedProvider::$sessions = [fn () => throw ProviderException::noResponse(), new SessionStatus(true, 'USER_CLOSED')];

        poll();
        runQueuedProcessing();

        expect(sessionCalls())->toBe(2)
            ->and(collect([pollFresh($first), pollFresh($second)])->map(fn (Conversation $c) => $c->status->value)->sort()->values()->all())->toBe(['ended', 'live']);
    });

    it('is retried by the next run, which finds the end', function () {
        $conversation = polled(['provider_session_ref' => 'sess-1']);
        ScriptedProvider::$sessions = [fn () => throw ProviderException::fromStatus(502), new SessionStatus(true, 'USER_CLOSED')];

        poll();
        expect(pollFresh($conversation)->status)->toBe(ConversationStatus::Live);
        poll();
        runQueuedProcessing();

        expect(pollFresh($conversation)->status)->toBe(ConversationStatus::Ended);
    });

    it('leaves a conversation whose credential is missing as it is, and says which one in the log', function () {
        $context = null;
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$context) {
            if ($event->message === 'Provider session lookup skipped') {
                $context = $event->context;
            }
        });
        config()->set('hr-avatar.providers.polled', ['adapter' => ScriptedProvider::class, 'credential' => true]);
        config()->set('hr-avatar.credential_providers', [...config('hr-avatar.credential_providers'), 'polled']);
        $conversation = polled();

        expect(poll())->toBe(0);

        expect($context)->toBe(['conversation_id' => $conversation->id, 'reason' => 'no_credential'])
            ->and(sessionCalls())->toBe(0)
            ->and(pollFresh($conversation)->status)->toBe(ConversationStatus::Live);
    });

    it('does not stop for a conversation it cannot store the end of: the others are processed and the command reports it', function () {
        DB::unprepared("CREATE FUNCTION refuse_one_receipt() RETURNS trigger AS \$\$ BEGIN IF NEW.provider_ref = 'sess-refused' THEN RAISE EXCEPTION 'refused with a secret in it'; END IF; RETURN NEW; END \$\$ LANGUAGE plpgsql");
        DB::unprepared('CREATE TRIGGER refuse_one_receipt BEFORE INSERT ON provider_webhook_receipts FOR EACH ROW EXECUTE FUNCTION refuse_one_receipt()');
        $logs = captureLogs();
        $refused = polled(['provider_session_ref' => 'sess-refused']);
        $fine = polled(['provider_session_ref' => 'sess-fine']);
        ScriptedProvider::$sessions = [new SessionStatus(true, 'USER_CLOSED'), new SessionStatus(true, 'USER_CLOSED')];

        expect(poll())->toBe(1);
        runQueuedProcessing();

        expect(pollFresh($refused)->status)->toBe(ConversationStatus::Live)
            ->and(pollFresh($fine)->status)->toBe(ConversationStatus::Ended)
            ->and(collect($logs)->contains(fn (string $line) => str_contains($line, 'Provider session poll failed') && str_contains($line, $refused->id)))->toBeTrue()
            ->and(collect($logs)->contains(fn (string $line) => str_contains($line, 'secret in it')))->toBeFalse();
    });
});

describe('which conversations are asked about', function () {
    beforeEach(fn () => Queue::fake());

    it('only open ones that hold a session', function (array $attributes) {
        polled($attributes);

        poll();

        expect(sessionCalls())->toBe(0);
    })->with([
        'created' => [['status' => ConversationStatus::Created, 'live_at' => null, 'connecting_at' => null, 'provider_session_ref' => null]],
        'connecting without a session yet' => [['status' => ConversationStatus::Connecting, 'live_at' => null, 'provider_session_ref' => null]],
        'ended by the api' => [['status' => ConversationStatus::Ended, 'ended_at' => now()->subMinute(), 'ended_reason' => EndedReason::Api]],
        'ended by the sweeper' => [['status' => ConversationStatus::Ended, 'ended_at' => now()->subMinute(), 'ended_reason' => EndedReason::MaxDuration]],
        'failed' => [['status' => ConversationStatus::Failed, 'ended_at' => now()->subMinute(), 'ended_reason' => EndedReason::ProviderError]],
    ]);

    it('both connecting and live ones that hold a session', function (ConversationStatus $status) {
        polled(['status' => $status]);

        poll();

        expect(sessionCalls())->toBe(1);
    })->with([ConversationStatus::Connecting, ConversationStatus::Live]);

    it('never those of a provider that calls back or has no events to poll', function () {
        Http::fake();
        // Tavus has its key and Mock's session has ended: only the kind of provider keeps them from being asked.
        config()->set('hr-avatar.platform_keys.tavus', 'platform-key-9');
        $tavus = polled(['provider' => 'tavus', 'provider_session_ref' => 'c123']);
        $mock = polled(['provider' => 'mock', 'provider_session_ref' => 'mock_x']);
        Cache::put('hra:mock:ended:mock_x', true, 600);

        poll();

        Http::assertNothingSent();
        expect(pollFresh($tavus)->status)->toBe(ConversationStatus::Live)->and(pollFresh($mock)->status)->toBe(ConversationStatus::Live)
            ->and(sessionCalls())->toBe(0)
            ->and(ProviderWebhookReceipt::withoutGlobalScopes()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('those of every account, each read under its own account', function () {
        $other = Account::factory()->create();
        inAccount($other, fn () => WebhookEndpoint::factory()->create());
        $mine = polled(['provider_session_ref' => 'sess-mine']);
        $theirs = polled(['provider_session_ref' => 'sess-theirs'], $other);
        ScriptedProvider::$sessions = [new SessionStatus(true, 'USER_CLOSED'), new SessionStatus(true, 'USER_CLOSED')];
        $selects = [];
        DB::listen(function ($query) use (&$selects) {
            if (preg_match('/^select .* from "conversations"/', $query->sql) === 1) {
                $selects[] = $query->sql;
            }
        });

        poll();
        runQueuedProcessing();
        // What the command and its jobs read; the checks below read the table themselves.
        $read = $selects;

        expect(pollFresh($mine)->status)->toBe(ConversationStatus::Ended)->and(pollFresh($theirs)->status)->toBe(ConversationStatus::Ended)
            ->and($read)->not->toBe([])
            ->and(array_values(array_filter($read, fn (string $sql) => ! str_contains($sql, '"account_id" = '))))->toBe([]);
    });

    it('in chunks, all of them', function () {
        foreach (range(1, 103) as $ignored) {
            polled();
        }

        poll();

        expect(sessionCalls())->toBe(103);
    });
});

describe('the Heygen adapter, end to end', function () {
    it('ends a conversation whose LiveAvatar session has ended, with LiveAvatar\'s own reason, and releases it', function () {
        Queue::fake();
        inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'heygen', 'api_key' => 'la-key-123456']));
        $conversation = polled(['provider' => 'heygen', 'provider_session_ref' => 'sess_1']);
        Http::fake(['api.liveavatar.com/v1/sessions/sess_1' => Http::response(['code' => 1000, 'data' => ['session_id' => 'sess_1', 'end_at' => '2026-10-19T11:59:00Z', 'end_reason' => 'IDLE_TIMEOUT']])]);

        poll();
        runQueuedProcessing();

        $row = pollFresh($conversation);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.liveavatar.com/v1/sessions/sess_1' && $request->header('X-API-KEY') === ['la-key-123456']);
        expect($row->status)->toBe(ConversationStatus::Ended)
            ->and($row->ended_reason)->toBe(EndedReason::Provider)
            ->and($row->provider_end_reason)->toBe('IDLE_TIMEOUT')
            ->and(ProviderWebhookReceipt::withoutGlobalScopes()->value('idempotency_key'))->toBe('liveavatar:sess_1:ended');
        Queue::assertPushed(ReleaseProviderSession::class, 1);
    });
});

describe('a provider that never says anything', function () {
    it('still has its conversation ended by the sweeper, its session released and its transcript reconciled', function () {
        config()->set('hr-avatar.platform_keys.tavus', 'platform-key-9');
        $conversation = polled(['provider' => 'tavus', 'provider_session_ref' => 'c123', 'connecting_at' => now()->subSeconds(2000)]);
        Http::fake([
            'tavusapi.com/v2/conversations/c123/end' => Http::response('', 200),
            'tavusapi.com/v2/conversations/c123*' => Http::response(['status' => 'ended', 'events' => [['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => [['role' => 'assistant', 'content' => 'Goodbye.']]]]]]),
            '*' => Http::response('', 204),
        ]);

        Artisan::call('hra:conversations:sweep');

        $row = pollFresh($conversation);
        expect($row->status)->toBe(ConversationStatus::Ended)
            ->and($row->ended_reason)->toBe(EndedReason::MaxDuration)
            ->and($row->released_at)->not->toBeNull()
            ->and($row->transcript_status)->toBe(TranscriptStatus::Ready);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://tavusapi.com/v2/conversations/c123/end');
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_starts_with($request->url(), 'https://tavusapi.com/v2/conversations/c123'));
    });
});

describe('the command', function () {
    it('is scheduled every minute and never overlaps itself', function () {
        $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event) => str_contains($event->command ?? '', 'hra:conversations:poll'));

        expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue();
    });
});
