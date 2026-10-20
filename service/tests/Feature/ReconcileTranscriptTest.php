<?php

use App\Accounts\CurrentAccount;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\TranscriptLine;
use App\AvatarProviders\TranscriptNotReady;
use App\AvatarProviders\TranscriptShapeError;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptStatus;
use App\Credentials\CredentialMissing;
use App\Credentials\ProviderAuth;
use App\Jobs\ReconcileTranscript;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ProviderCredential;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AvatarProviders\ScriptedProvider;

beforeEach(function () {
    Queue::fake();
    ScriptedProvider::register();
    $this->account = Account::factory()->create();
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function endedConversation(array $attributes = [], ?Account $account = null, string $provider = 'scripted'): Conversation
{
    $account ??= test()->account;
    $config = inAccount($account, fn () => AvatarConfig::factory()->create());

    return inAccount($account, fn () => Conversation::factory()->create([
        'avatar_config_id' => $config->id, 'provider' => $provider, 'status' => ConversationStatus::Ended, 'ended_reason' => EndedReason::Api,
        'ended_at' => now()->subMinutes(2), 'provider_session_ref' => 'sess-1', ...$attributes,
    ]));
}

function runReconcile(Conversation $conversation, ?string $accountId = null): void
{
    app()->call([new ReconcileTranscript($accountId ?? $conversation->account_id, $conversation->id), 'handle']);
}

function reloaded(Conversation $conversation): Conversation
{
    return Conversation::withoutGlobalScopes()->findOrFail($conversation->id);
}

/**
 * @return list<array{0: string, 1: string, 2: string}>
 */
function storedLines(Conversation $conversation): array
{
    return ConversationEvent::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('sequence')->get()->map(fn ($e) => [$e->source, $e->role, $e->text])->all();
}

describe('a run', function () {
    it('fetches the provider\'s transcript, merges it and marks the conversation ready', function () {
        $conversation = endedConversation();
        ScriptedProvider::$transcripts = [fn () => [new TranscriptLine('avatar', 'Hi', null, null), new TranscriptLine('user', 'Hello', null, null)]];

        runReconcile($conversation);

        expect(reloaded($conversation)->transcript_status)->toBe(TranscriptStatus::Ready)
            ->and(storedLines($conversation))->toBe([['provider', 'avatar', 'Hi'], ['provider', 'user', 'Hello']])
            ->and(ScriptedProvider::$calls)->toBe([['fetchTranscript', 'sess-1', null, ProviderAuth::NONE]]);
        expect(WebhookDelivery::withoutGlobalScopes()->where('event_type', 'transcript.ready')->count())->toBe(1);
    });

    it('does nothing for a transcript that is already finished: no provider call, no second event', function (TranscriptStatus $status) {
        $conversation = endedConversation(['transcript_status' => $status]);

        runReconcile($conversation);

        expect(ScriptedProvider::$calls)->toBe([])->and(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);
    })->with([TranscriptStatus::Ready, TranscriptStatus::Partial]);

    it('does nothing for a conversation that does not exist', function () {
        app()->call([new ReconcileTranscript($this->account->id, '01j0000000000000000000000x'), 'handle']);

        expect(ScriptedProvider::$calls)->toBe([]);
    });

    it('finishes at once, as an empty ready transcript, a conversation that never had a provider session', function () {
        $conversation = endedConversation(['provider_session_ref' => null]);

        runReconcile($conversation);

        expect(reloaded($conversation)->transcript_status)->toBe(TranscriptStatus::Ready)->and(storedLines($conversation))->toBe([])->and(ScriptedProvider::$calls)->toBe([]);
    });

    it('does not touch a conversation that has not ended', function () {
        $conversation = endedConversation(['status' => ConversationStatus::Live, 'ended_reason' => null, 'ended_at' => null]);
        ScriptedProvider::$transcripts = [fn () => [new TranscriptLine('user', 'Hi', null, null)]];

        runReconcile($conversation);

        expect(reloaded($conversation)->transcript_status)->toBe(TranscriptStatus::Pending)->and(storedLines($conversation))->toBe([]);
    });
});

describe('a transcript that is not there yet', function () {
    it('lets a not-ready answer and a provider failure through, so the queue retries, and leaves the transcript pending', function (Throwable $failure) {
        $conversation = endedConversation();
        ScriptedProvider::$transcripts = [fn () => throw $failure];

        expect(fn () => runReconcile($conversation))->toThrow($failure::class);

        expect(reloaded($conversation)->transcript_status)->toBe(TranscriptStatus::Pending)->and(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);
    })->with([
        'not ready' => [fn () => new TranscriptNotReady('The transcript is not ready.')],
        'provider failure' => [fn () => ProviderException::fromStatus(503)],
        'no response' => [fn () => ProviderException::noResponse()],
    ]);

    it('asks again five times, 30 s, 60 s, 120 s and 300 s apart', function () {
        $job = new ReconcileTranscript($this->account->id, 'conv_x');

        expect($job->tries)->toBe(5)->and($job->backoff())->toBe([30, 60, 120, 300]);
    });

    it('finishes as partial when the attempts are used up, keeping the client lines, and says so once', function () {
        $conversation = endedConversation();
        inAccount($this->account, fn () => ConversationEvent::forceCreate([
            'conversation_id' => $conversation->id, 'sequence' => 1, 'source' => 'client', 'type' => 'utterance', 'role' => 'user', 'text' => 'Kept', 'idempotency_key' => 'k-1',
        ]));

        $job = new ReconcileTranscript($this->account->id, $conversation->id);
        $job->failed(new TranscriptNotReady('gave up'));
        $job->failed(new TranscriptNotReady('gave up again'));

        $deliveries = WebhookDelivery::withoutGlobalScopes()->where('event_type', 'transcript.ready')->get();
        expect(reloaded($conversation)->transcript_status)->toBe(TranscriptStatus::Partial)
            ->and(storedLines($conversation))->toBe([['client', 'user', 'Kept']])
            ->and($deliveries)->toHaveCount(1)
            ->and(json_decode($deliveries[0]->payload, true)['data']['transcript'])->toBe(['status' => 'partial', 'utterance_count' => 1]);
    });

    it('does not turn a finished transcript into a partial one when it fails late', function () {
        $conversation = endedConversation(['transcript_status' => TranscriptStatus::Ready]);

        (new ReconcileTranscript($this->account->id, $conversation->id))->failed(new RuntimeException('late'));

        expect(reloaded($conversation)->transcript_status)->toBe(TranscriptStatus::Ready);
    });

    it('does nothing when it fails for a conversation that is gone or belongs to another account', function () {
        $conversation = endedConversation();

        (new ReconcileTranscript($this->account->id, '01j0000000000000000000000x'))->failed(new RuntimeException('x'));
        (new ReconcileTranscript(Account::factory()->create()->id, $conversation->id))->failed(new RuntimeException('x'));

        expect(reloaded($conversation)->transcript_status)->toBe(TranscriptStatus::Pending);
    });

    it('logs the conversation and the kind of failure, never its message', function () {
        $logs = captureLogs();
        $conversation = endedConversation();

        (new ReconcileTranscript($this->account->id, $conversation->id))->failed(new RuntimeException('PROVIDER-MESSAGE-WITH-SECRET'));

        $everything = implode("\n", $logs->getArrayCopy());
        expect($everything)->toContain($conversation->id)->and($everything)->not->toContain('PROVIDER-MESSAGE-WITH-SECRET');
    });
});

describe('a transcript in a shape that cannot be read', function () {
    it('fails the job at once instead of retrying: it does not throw, and tells the queue the job failed', function () {
        $conversation = endedConversation();
        $unreadable = new TranscriptShapeError('A transcript row has an unknown role.');
        ScriptedProvider::$transcripts = [fn () => throw $unreadable];
        // A worker answers a failed job by running its failure hook, which finishes the transcript as partial (tested above).
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('fail')->once()->with($unreadable);
        $job = new ReconcileTranscript($this->account->id, $conversation->id);
        $job->setJob($queueJob);

        app()->call([$job, 'handle']);

        expect(ScriptedProvider::$calls)->toHaveCount(1)->and(reloaded($conversation)->transcript_status)->toBe(TranscriptStatus::Pending);
    });
});

describe('the provider\'s credential', function () {
    it('is resolved where the job runs: the account\'s key when the provider needs one', function () {
        inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'tv-key-123456']));
        $conversation = endedConversation(provider: 'tavus');
        Http::fake(['*' => Http::response(['status' => 'ended', 'events' => [['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => [['role' => 'user', 'content' => 'Hi']]]]]], 200)]);

        runReconcile($conversation);

        Http::assertSent(fn ($request) => $request->header('x-api-key') === ['tv-key-123456']);
        expect(storedLines($conversation))->toBe([['provider', 'user', 'Hi']]);
    });

    // Ruling 54: a missing key will not appear by waiting, so the five attempts are not spent on it.
    it('fails the job at once when the provider needs a key and there is none, without any request', function () {
        config()->set('hr-avatar.platform_keys.tavus', null);
        $conversation = endedConversation(provider: 'tavus');
        Http::fake();
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('fail')->once()->with(Mockery::type(CredentialMissing::class));
        $job = new ReconcileTranscript($this->account->id, $conversation->id);
        $job->setJob($queueJob);

        app()->call([$job, 'handle']);

        Http::assertNothingSent();
        expect(reloaded($conversation)->transcript_status)->toBe(TranscriptStatus::Pending);
    });

    it('finishes the transcript as partial right away when the key is missing, with what the browser forwarded', function () {
        config()->set('hr-avatar.platform_keys.tavus', null);
        $conversation = endedConversation(provider: 'tavus');
        inAccount($this->account, fn () => ConversationEvent::factory()->create(['conversation_id' => $conversation->id, 'text' => 'Hello there']));
        $job = new ReconcileTranscript($this->account->id, $conversation->id);
        $queueJob = Mockery::mock(QueueJob::class);
        // What a worker does with a job that failed itself: it runs the failure hook.
        $queueJob->shouldReceive('fail')->once()->andReturnUsing(fn (Throwable $e) => $job->failed($e));
        $job->setJob($queueJob);

        app()->call([$job, 'handle']);

        expect(reloaded($conversation)->transcript_status)->toBe(TranscriptStatus::Partial)
            ->and(storedLines($conversation))->toHaveCount(1);
    });

    it('never travels in the job: the job holds the two ids only', function () {
        $job = new ReconcileTranscript($this->account->id, 'conv_x');

        expect(array_keys(get_object_vars($job)))->toContain('accountId', 'conversationId')
            ->and(serialize($job))->not->toContain('api_key');
    });
});

describe('the account', function () {
    it('is the job\'s own: a job for another account finds no conversation and does nothing', function () {
        $conversation = endedConversation();
        ScriptedProvider::$transcripts = [fn () => [new TranscriptLine('user', 'Hi', null, null)]];

        runReconcile($conversation, Account::factory()->create()->id);

        expect(ScriptedProvider::$calls)->toBe([])->and(reloaded($conversation)->transcript_status)->toBe(TranscriptStatus::Pending);
    });

    it('is bound for the run and left as it was found', function () {
        $conversation = endedConversation();
        expect(app(CurrentAccount::class)->id())->toBeNull();

        runReconcile($conversation);

        expect(app(CurrentAccount::class)->id())->toBeNull();
    });
});
