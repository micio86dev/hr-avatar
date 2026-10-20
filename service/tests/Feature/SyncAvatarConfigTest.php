<?php

use App\AvatarProviders\ProviderException;
use App\AvatarProviders\SyncStatus;
use App\Conversations\AvatarConfigSync;
use App\Jobs\SyncAvatarConfig;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\ProviderCredential;
use Illuminate\Queue\InvalidPayloadException;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->account = Account::factory()->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function syncJobConfig(array $attributes = [], ?Account $account = null): AvatarConfig
{
    return inAccount($account ?? test()->account, fn () => AvatarConfig::factory()->create([
        'provider' => 'tavus',
        'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_123'],
        'persona' => ['llm_temperature' => 0.5],
        'sync_status' => SyncStatus::Pending,
        ...$attributes,
    ]));
}

function syncJobStoreKey(string $provider, string $key, ?Account $account = null): void
{
    inAccount($account ?? test()->account, fn () => ProviderCredential::create(['provider' => $provider, 'api_key' => $key]));
}

function runSync(AvatarConfig $config): void
{
    SyncAvatarConfig::dispatchSync($config->account_id, $config->id);
}

describe('a successful run', function () {
    it('marks the config synced, stamps the time and clears an earlier error', function () {
        syncJobStoreKey('tavus', 'tv-key-123456');
        Http::fake(['*' => Http::response([], 200)]);
        $config = syncJobConfig(['sync_error' => 'Provider request failed (status 500)']);

        runSync($config);

        $config->refresh();
        expect($config->sync_status)->toBe(SyncStatus::Synced)
            ->and($config->synced_at)->not->toBeNull()
            ->and($config->sync_error)->toBeNull();
        Http::assertSentCount(1);
    });

    it('authenticates with the account key, and with the platform key when the account has none', function (?string $own, string $sent) {
        config()->set('hr-avatar.platform_keys.tavus', 'platform-key-9');
        if ($own !== null) {
            syncJobStoreKey('tavus', $own);
        }
        Http::fake(['*' => Http::response([], 200)]);

        runSync(syncJobConfig());

        Http::assertSent(fn ($request) => $request->header('x-api-key') === [$sent]);
    })->with([
        'account key' => ['own-key-123456', 'own-key-123456'],
        'platform key' => [null, 'platform-key-9'],
    ]);

    it('syncs a LiveAvatar config with a model and an external voice', function () {
        LlmModel::factory()->create(['id' => 'gem-1', 'provider' => 'gemini', 'model' => 'gemini-x']);
        inAccount($this->account, fn () => LlmCredential::create(['llm_provider' => 'gemini', 'api_key' => 'gm-key-123456']));
        syncJobStoreKey('heygen', 'la-key-123456');
        syncJobStoreKey('cartesia', 'CARTESIA_KEY_X');
        Http::fake([
            'api.liveavatar.com/v1/secrets' => Http::sequence()->push(['data' => ['id' => 'sec-1']])->push(['data' => ['id' => 'sec-2']]),
            'api.liveavatar.com/v1/llm-configurations' => Http::response(['data' => ['id' => 'llm-1']]),
            'api.liveavatar.com/v1/voices/third_party' => Http::response(['data' => ['voice_id' => 'bound-1']]),
        ]);
        $config = syncJobConfig(['provider' => 'heygen', 'config' => ['avatarId' => 'a'], 'persona' => null, 'llm_model_id' => 'gem-1', 'voice' => ['engine' => 'cartesia', 'voice_id' => 'v-1']]);

        runSync($config);

        expect($config->refresh()->sync_status)->toBe(SyncStatus::Synced)->and($config->provider_state)->toBe(['llm_configuration_id' => 'llm-1']);
    });

    it('is idempotent: a second run updates, it does not create again', function () {
        syncJobStoreKey('tavus', 'tv-key-123456');
        Http::fake(['*' => Http::response([], 200)]);
        $config = syncJobConfig();

        runSync($config);
        runSync($config);

        Http::assertSentCount(2);
        expect(Http::recorded()->every(fn (array $pair) => $pair[0]->method() === 'PATCH' && $pair[0]->url() === 'https://tavusapi.com/v2/pals/p_123'))->toBeTrue();
    });

    it('settles a config whose provider needs nothing as not required, without a call', function () {
        Http::fake();
        $config = syncJobConfig(['provider' => 'mock', 'config' => [], 'persona' => null]);

        runSync($config);

        expect($config->refresh()->sync_status)->toBe(SyncStatus::NotRequired)->and($config->synced_at)->toBeNull();
        Http::assertNothingSent();
    });
});

describe('a synchronous queue', function () {
    it('runs the sync inside the dispatch and leaves the config as the job settled it', function () {
        syncJobStoreKey('tavus', 'tv-key-123456');
        Http::fake(['*' => Http::response([], 200)]);
        $config = syncJobConfig();

        AvatarConfigSync::dispatchWhenPending($config);

        expect($config->refresh()->sync_status)->toBe(SyncStatus::Synced)->and($config->sync_error)->toBeNull();
    });
});

describe('a failed run', function () {
    it('records the failure with a message that holds neither the key nor the provider body', function () {
        syncJobStoreKey('tavus', 'tv-key-123456');
        Http::fake(['*' => Http::response(['error' => 'bad key tv-key-123456', 'detail' => 'PROVIDER-BODY'], 500)]);
        $logs = captureLogs();
        $config = syncJobConfig();

        try {
            runSync($config);
        } catch (Throwable) {
            // The sync queue re-raises after recording the failure; the stored state is what matters.
        }

        $config->refresh();
        expect($config->sync_status)->toBe(SyncStatus::Failed)
            ->and($config->sync_error)->toBe('Provider request failed (status 500)')
            ->and($config->synced_at)->toBeNull()
            ->and(implode("\n", $logs->getArrayCopy()))->not->toContain('tv-key-123456')->not->toContain('PROVIDER-BODY');
    });

    it('does not retry a sync that cannot start: no provider key is a failure at once, with nothing sent', function () {
        Http::fake();
        $config = syncJobConfig();

        runSync($config);

        expect($config->refresh()->sync_status)->toBe(SyncStatus::Failed)->and($config->sync_error)->toBe('The provider credential is missing.');
        Http::assertNothingSent();
    });

    // Review rv17 R3-002: a refusal (401, 403, 404, 422) is the same answer two minutes later; only a throttle or an upstream failure is worth another attempt.
    it('does not retry a request the provider refused: the failure is recorded at once and nothing is thrown', function (int $status) {
        syncJobStoreKey('tavus', 'tv-key-123456');
        Http::fake(['*' => Http::response([], $status)]);
        $config = syncJobConfig();

        runSync($config);

        expect($config->refresh()->sync_status)->toBe(SyncStatus::Failed)->and($config->sync_error)->toBe("Provider request failed (status {$status})");
        Http::assertSentCount(1);
    })->with([401, 403, 404, 422]);

    it('still lets a throttle and a provider failure through, so the queue retries them', function (int $status) {
        syncJobStoreKey('tavus', 'tv-key-123456');
        Http::fake(['*' => Http::response([], $status)]);
        $config = syncJobConfig();

        expect(fn () => runSync($config))->toThrow(ProviderException::class);
    })->with([429, 500, 503]);

    // Review sf9 R3-avatar-config-sync-catch-scope: a queue that runs the job inside the push (QUEUE_CONNECTION=sync) must not have the
    // job's own failure read as a push that failed: the job recorded its reason, and the caller sees the failure it raised.
    it('does not label a failure of the job run by a synchronous queue as a sync that could not be queued', function () {
        syncJobStoreKey('tavus', 'tv-key-123456');
        Http::fake(['*' => Http::response([], 500)]);
        $logs = captureLogs();
        $config = syncJobConfig();

        expect(fn () => AvatarConfigSync::dispatchWhenPending($config))->toThrow(ProviderException::class);

        expect($config->refresh()->sync_status)->toBe(SyncStatus::Failed)->and($config->sync_error)->toBe('Provider request failed (status 500)')
            ->and(implode("\n", $logs->getArrayCopy()))->not->toContain('could not be queued');
    });

    // Review of sf9 (warning): the synchronous queue builds the payload before it runs the job, so a push that fails there
    // (the job cannot be serialized) is a push that failed, exactly as on a queue that is down: the job never ran and
    // recorded nothing, so the config must not stay `pending` for ever.
    it('records a push that fails before the job runs as a sync that could not be queued, and does not raise it', function () {
        $logs = captureLogs();
        $config = syncJobConfig();
        $queue = new class extends SyncQueue
        {
            public function connection(): static
            {
                return $this;
            }

            protected function createPayload($job, $queue, $data = '', $delay = null)
            {
                throw new InvalidPayloadException('cannot serialize SECRET-JOB');
            }
        };
        $queue->setContainer(app());
        Queue::swap($queue);

        AvatarConfigSync::dispatchWhenPending($config);

        expect($config->refresh()->sync_status)->toBe(SyncStatus::Failed)->and($config->sync_error)->toBe(AvatarConfigSync::NOT_QUEUED);
        $everything = implode("\n", $logs->getArrayCopy());
        expect($everything)->toContain('Avatar config sync could not be queued')->toContain('InvalidPayloadException')->not->toContain('SECRET-JOB');
    });

    it('says what is missing when a bound model has no key', function () {
        syncJobStoreKey('tavus', 'tv-key-123456');
        LlmModel::factory()->create(['id' => 'gem-1', 'provider' => 'gemini']);
        Http::fake();
        $config = syncJobConfig(['llm_model_id' => 'gem-1']);

        runSync($config);

        expect($config->refresh()->sync_status)->toBe(SyncStatus::Failed)->and($config->sync_error)->toBe('The language model credential is missing.');
        Http::assertNothingSent();
    });

    it('records a fixed sentence for an exception it does not know, never its message', function () {
        $config = syncJobConfig();

        (new SyncAvatarConfig($config->account_id, $config->id))->failed(new RuntimeException('connection to 10.0.0.5 refused, password=hunter2'));

        expect($config->refresh()->sync_status)->toBe(SyncStatus::Failed)->and($config->sync_error)->toBe('Synchronization failed.');
    });

    it('does nothing for a config that was deleted meanwhile', function () {
        Http::fake();
        $config = syncJobConfig();
        $config->delete();

        runSync($config);
        (new SyncAvatarConfig($config->account_id, $config->id))->failed(new RuntimeException('x'));

        Http::assertNothingSent();
        expect(inAccount($this->account, fn () => AvatarConfig::withTrashed()->find($config->id)?->sync_status))->toBe(SyncStatus::Pending);
    });

    it('cannot reach another account\'s config through its id', function () {
        Http::fake();
        $foreign = syncJobConfig([], Account::factory()->create());

        SyncAvatarConfig::dispatchSync($this->account->id, $foreign->id);
        (new SyncAvatarConfig($this->account->id, $foreign->id))->failed(new RuntimeException('x'));

        Http::assertNothingSent();
        expect(syncJobStatusOf($foreign))->toBe(SyncStatus::Pending);
    });
});

function syncJobStatusOf(AvatarConfig $config): SyncStatus
{
    return inAccount(Account::findOrFail($config->account_id), fn () => AvatarConfig::findOrFail($config->id)->sync_status);
}

describe('the job', function () {
    it('tries three times with a growing wait', function () {
        $job = new SyncAvatarConfig('acc', 'avc_x');

        expect($job->tries)->toBe(3)->and($job->backoff())->toBe([30, 120]);
    });

    it('carries ids only, so no key can sit in the queue', function () {
        $payload = serialize(new SyncAvatarConfig('acc_1', 'avc_2'));

        expect($payload)->toContain('acc_1')->toContain('avc_2')->not->toContain('key');
    });
});

describe('the dispatch rule', function () {
    it('queues a sync for a pending config and for nothing else', function (SyncStatus $status, bool $queued) {
        Queue::fake();
        $config = syncJobConfig(['sync_status' => $status]);

        AvatarConfigSync::dispatchWhenPending($config);

        $queued ? Queue::assertPushed(SyncAvatarConfig::class, fn (SyncAvatarConfig $job) => $job->accountId === $config->account_id && $job->avatarConfigId === $config->id) : Queue::assertNothingPushed();
    })->with([
        'pending' => [SyncStatus::Pending, true],
        'synced' => [SyncStatus::Synced, false],
        'failed' => [SyncStatus::Failed, false],
        'not required' => [SyncStatus::NotRequired, false],
    ]);
});
