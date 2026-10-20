<?php

use App\Accounts\CurrentAccount;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Jobs\ReconcileTranscript;
use App\Jobs\ReleaseProviderSession;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ProviderCredential;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\Support\AvatarProviders\ScriptedProvider;

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-10-19 12:00:00');
    ScriptedProvider::register();
    $this->account = Account::factory()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function releasable(array $attributes = [], ?Account $account = null, string $provider = 'scripted'): Conversation
{
    $account ??= test()->account;
    $config = inAccount($account, fn () => AvatarConfig::factory()->create());

    return inAccount($account, fn () => Conversation::factory()->create([
        'avatar_config_id' => $config->id, 'provider' => $provider, 'status' => ConversationStatus::Ended, 'ended_reason' => EndedReason::Api,
        'ended_at' => now()->subSeconds(20), 'provider_session_ref' => 'sess-1', 'provider_context_ref' => 'ctx-1', ...$attributes,
    ]));
}

/**
 * Runs the job as a worker would on its Nth attempt, and returns how it released itself back to the queue (null if it did not).
 */
function releaseOnAttempt(Conversation $conversation, int $attempt = 1, ?string $accountId = null): ?int
{
    $released = null;
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempt);
    $queueJob->shouldReceive('release')->andReturnUsing(function (int $delay) use (&$released) {
        $released = $delay;
    });
    $job = new ReleaseProviderSession($accountId ?? $conversation->account_id, $conversation->id);
    $job->setJob($queueJob);

    app()->call([$job, 'handle']);

    return $released;
}

function released(Conversation $conversation): ?Carbon
{
    return Conversation::withoutGlobalScopes()->findOrFail($conversation->id)->released_at;
}

describe('a release', function () {
    it('tears the session down once, with its references, and stamps the release', function () {
        $conversation = releasable();

        $delay = releaseOnAttempt($conversation);

        expect(ScriptedProvider::$calls)->toBe([['teardown', 'sess-1', 'ctx-1', 'none']])
            ->and($delay)->toBeNull()
            ->and(released($conversation)->equalTo(now()))->toBeTrue();
    });

    it('does nothing for a conversation already released: no teardown, no reconciliation', function () {
        $conversation = releasable(['released_at' => now()->subMinute()]);

        releaseOnAttempt($conversation);

        expect(ScriptedProvider::$calls)->toBe([])->and(released($conversation)->equalTo(now()->subMinute()))->toBeTrue();
        Queue::assertNothingPushed();
    });

    it('does nothing for a conversation that never had a provider session, or that does not exist', function () {
        $never = releasable(['provider_session_ref' => null]);

        releaseOnAttempt($never);
        app()->call([new ReleaseProviderSession($this->account->id, '01j0000000000000000000000x'), 'handle']);

        expect(ScriptedProvider::$calls)->toBe([])->and(released($never))->toBeNull();
        Queue::assertNothingPushed();
    });

    it('releases a conversation that failed after its session was created, as it does one that ended', function () {
        $conversation = releasable(['status' => ConversationStatus::Failed, 'ended_reason' => EndedReason::ProviderError]);

        releaseOnAttempt($conversation);

        expect(ScriptedProvider::$calls)->toHaveCount(1)->and(released($conversation))->not->toBeNull();
    });

    it('leaves the conversation as it was: only the release is stamped', function () {
        $conversation = releasable();
        $before = Conversation::withoutGlobalScopes()->find($conversation->id)->getAttributes();

        releaseOnAttempt($conversation);

        $after = Conversation::withoutGlobalScopes()->find($conversation->id)->getAttributes();
        expect(array_keys(array_diff_assoc($after, $before)))->toBe(['released_at']);
    });
});

describe('teardown that does not succeed', function () {
    it('releases the job back with 10 s, 30 s, 60 s and 120 s, and tries again', function (int $attempt, int $delay) {
        $conversation = releasable();
        ScriptedProvider::$teardowns = [false];

        expect(releaseOnAttempt($conversation, $attempt))->toBe($delay)->and(released($conversation))->toBeNull();
    })->with([[1, 10], [2, 30], [3, 60], [4, 120]]);

    it('allows five attempts', function () {
        $job = new ReleaseProviderSession($this->account->id, 'conv_x');

        expect($job->tries)->toBe(5)->and($job->backoff())->toBe([10, 30, 60, 120]);
    });

    it('is invoked three times when it fails twice and then succeeds', function () {
        $conversation = releasable();
        ScriptedProvider::$teardowns = [false, false, true];

        foreach ([1, 2, 3] as $attempt) {
            releaseOnAttempt($conversation, $attempt);
        }

        expect(array_column(ScriptedProvider::$calls, 0))->toBe(['teardown', 'teardown', 'teardown'])->and(released($conversation))->not->toBeNull();
    });

    it('gives up after the fifth failure: nothing is released back, the state is untouched and the log holds no credential', function () {
        $logs = captureLogs();
        $conversation = releasable();
        ScriptedProvider::$teardowns = [false];
        $before = Conversation::withoutGlobalScopes()->find($conversation->id)->getAttributes();

        $delay = releaseOnAttempt($conversation, 5);

        expect($delay)->toBeNull()
            ->and(Conversation::withoutGlobalScopes()->find($conversation->id)->getAttributes())->toBe($before)
            ->and(Conversation::withoutGlobalScopes()->find($conversation->id)->status)->toBe(ConversationStatus::Ended);
        $log = implode("\n", $logs->getArrayCopy());
        expect($log)->toContain($conversation->id)->not->toContain('sess-1')->not->toContain('ctx-1');
    });

    it('treats a provider that needs a key the account does not have as a teardown that failed', function () {
        config()->set('hr-avatar.platform_keys.tavus', null);
        $conversation = releasable(provider: 'tavus');
        Http::fake();

        expect(releaseOnAttempt($conversation, 1))->toBe(10);

        Http::assertNothingSent();
        expect(released($conversation))->toBeNull();
    });

    it('logs only the kind of failure when the job itself dies, never its message', function () {
        $logs = captureLogs();
        $conversation = releasable();

        (new ReleaseProviderSession($this->account->id, $conversation->id))->failed(new RuntimeException('PROVIDER-MESSAGE-WITH-SECRET'));

        $log = implode("\n", $logs->getArrayCopy());
        expect($log)->toContain($conversation->id)->not->toContain('PROVIDER-MESSAGE-WITH-SECRET');
    });
});

describe('the transcript', function () {
    it('is reconciled by a job queued on the first attempt, whatever happens to the teardown', function (bool $teardown) {
        $conversation = releasable();
        ScriptedProvider::$teardowns = [$teardown];

        releaseOnAttempt($conversation, 1);

        Queue::assertPushed(ReconcileTranscript::class, 1);
        Queue::assertPushed(ReconcileTranscript::class, fn (ReconcileTranscript $job) => $job->accountId === $this->account->id && $job->conversationId === $conversation->id);
    })->with([true, false]);

    it('is queued once however many attempts run: a retry does not queue it again', function () {
        $conversation = releasable();
        ScriptedProvider::$teardowns = [false, false, false];

        foreach ([1, 2, 3] as $attempt) {
            releaseOnAttempt($conversation, $attempt);
        }

        Queue::assertPushed(ReconcileTranscript::class, 1);
    });

    // Review rv23 R3-001: a first attempt that died before it queued the job (a database error on the lookup) must not leave the transcript pending for good.
    it('is queued by the retry when the first attempt died before it could queue it', function () {
        $conversation = releasable();

        releaseOnAttempt($conversation, 2);

        Queue::assertPushed(ReconcileTranscript::class, 1);
    });

    it('is queued again by the next attempt when the push itself failed', function () {
        $conversation = releasable();
        $queue = new class(app()) extends QueueFake
        {
            public bool $down = true;

            public function push($job, $data = '', $queue = null)
            {
                return $this->down ? throw new RuntimeException('queue is down') : parent::push($job, $data, $queue);
            }

            public function later($delay, $job, $data = '', $queue = null)
            {
                return $this->down ? throw new RuntimeException('queue is down') : parent::later($delay, $job, $data, $queue);
            }
        };
        Queue::swap($queue);

        expect(fn () => releaseOnAttempt($conversation, 1))->toThrow(RuntimeException::class);
        $queue->down = false;
        releaseOnAttempt($conversation, 2);

        $queue->assertPushed(ReconcileTranscript::class, 1);
    });

    it('waits until 60 seconds after the end: the browser\'s events have their window first', function (int $endedSecondsAgo, int $delay) {
        $conversation = releasable(['ended_at' => now()->subSeconds($endedSecondsAgo)]);

        releaseOnAttempt($conversation, 1);

        Queue::assertPushed(ReconcileTranscript::class, fn (ReconcileTranscript $job) => $job->delay === $delay);
    })->with([
        'just ended' => [0, 60],
        'ended 20 s ago' => [20, 40],
        'ended 59 s ago' => [59, 1],
        'ended exactly 60 s ago' => [60, 0],
        'ended long ago' => [3600, 0],
    ]);

    it('does not depend on any provider webhook having arrived: an ended Tavus conversation is torn down and reconciled', function () {
        inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'tv-key-123456']));
        $conversation = releasable(provider: 'tavus', attributes: ['provider_context_ref' => null]);
        Http::fake(['*' => Http::response('', 200)]);

        releaseOnAttempt($conversation, 1);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/conversations/sess-1/end') && $request->header('x-api-key') === ['tv-key-123456']);
        Queue::assertPushed(ReconcileTranscript::class, 1);
        expect(released($conversation))->not->toBeNull();
    });

    it('does not need a webhook endpoint or a delivery to run', function () {
        $conversation = releasable();

        releaseOnAttempt($conversation, 1);

        expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0)->and(WebhookEndpoint::withoutGlobalScopes()->count())->toBe(0)->and(released($conversation))->not->toBeNull();
    });
});

describe('the account', function () {
    it('is the job\'s own: a job for another account finds no conversation and does nothing', function () {
        $conversation = releasable();

        releaseOnAttempt($conversation, 1, Account::factory()->create()->id);

        expect(ScriptedProvider::$calls)->toBe([])->and(released($conversation))->toBeNull();
        Queue::assertNothingPushed();
    });

    it('is bound for the run and left as it was found; the job holds the two ids only', function () {
        $conversation = releasable();
        expect(app(CurrentAccount::class)->id())->toBeNull();

        releaseOnAttempt($conversation);

        expect(app(CurrentAccount::class)->id())->toBeNull()
            ->and(array_keys(get_object_vars(new ReleaseProviderSession('a', 'c'))))->toContain('accountId', 'conversationId');
    });
});
