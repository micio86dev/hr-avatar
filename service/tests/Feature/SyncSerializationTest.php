<?php

use App\AvatarProviders\SyncStatus;
use App\Jobs\Middleware\SerializeConfigSync;
use App\Jobs\SyncAvatarConfig;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\ProviderCredential;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->account = Account::factory()->create();
    inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'tv-key-123456']));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function serialConfig(?Account $account = null, array $attributes = []): AvatarConfig
{
    return inAccount($account ?? test()->account, fn () => AvatarConfig::factory()->create([
        'provider' => 'tavus',
        'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1'],
        'persona' => ['llm_temperature' => 0.5],
        'sync_status' => SyncStatus::Pending,
        ...$attributes,
    ]));
}

/**
 * Runs a job the way a worker does: through its own middleware, then its handler.
 */
function throughMiddleware(SyncAvatarConfig $job): void
{
    (new Pipeline(app()))->send($job)->through($job->middleware())->then(fn (SyncAvatarConfig $job) => app()->call([$job, 'handle']));
}

function holdSyncLock(AvatarConfig $config): void
{
    expect(Cache::lock("hra:sync-config:{$config->account_id}:{$config->id}", 300)->get())->toBeTrue();
}

describe('the middleware', function () {
    it('is part of the sync job', function () {
        $config = serialConfig();
        $middleware = (new SyncAvatarConfig($config->account_id, $config->id))->middleware();

        expect($middleware)->toHaveCount(1)->and($middleware[0])->toBeInstanceOf(SerializeConfigSync::class);
    });

    it('lets a run through when no other run of that config is in flight, and frees the config afterwards', function () {
        $config = serialConfig();
        $ran = 0;
        $job = new SyncAvatarConfig($config->account_id, $config->id);

        (new SerializeConfigSync)->handle($job, function () use (&$ran) {
            $ran++;
        });
        (new SerializeConfigSync)->handle($job, function () use (&$ran) {
            $ran++;
        });

        expect($ran)->toBe(2);
    });

    it('frees the config when the run throws', function () {
        $config = serialConfig();
        $job = new SyncAvatarConfig($config->account_id, $config->id);

        expect(fn () => (new SerializeConfigSync)->handle($job, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);

        $ran = false;
        (new SerializeConfigSync)->handle($job, function () use (&$ran) {
            $ran = true;
        });
        expect($ran)->toBeTrue();
    });

    it('does not run a second run of the same config: it queues a fresh copy a little later', function () {
        $config = serialConfig(attributes: ['sync_revision' => 4]);
        holdSyncLock($config);
        $ran = false;

        (new SerializeConfigSync)->handle(new SyncAvatarConfig($config->account_id, $config->id, 4), function () use (&$ran) {
            $ran = true;
        });

        expect($ran)->toBeFalse();
        Queue::assertPushed(SyncAvatarConfig::class, fn (SyncAvatarConfig $copy) => $copy->accountId === $config->account_id
            && $copy->avatarConfigId === $config->id
            && $copy->revision === 4
            && $copy->delay > 0);
        Queue::assertPushed(SyncAvatarConfig::class, 1);
    });

    it('spends none of the job\'s attempts while it waits: the waiting run is not released', function () {
        $config = serialConfig();
        holdSyncLock($config);
        $job = new SyncAvatarConfig($config->account_id, $config->id);
        $queued = Mockery::mock(Job::class);
        $queued->shouldNotReceive('release');
        $job->setJob($queued);

        (new SerializeConfigSync)->handle($job, fn () => throw new LogicException('must not run'));
    });

    it('does not hold up another config, or the same id under another account', function (bool $sameAccount) {
        $held = serialConfig();
        holdSyncLock($held);
        $other = $sameAccount ? serialConfig() : serialConfig(Account::factory()->create());
        $ran = false;

        (new SerializeConfigSync)->handle(new SyncAvatarConfig($other->account_id, $other->id), function () use (&$ran) {
            $ran = true;
        });

        expect($ran)->toBeTrue();
        Queue::assertNothingPushed();
    })->with(['another config' => [true], 'another account' => [false]]);
});

describe('two runs of one config', function () {
    it('make one provider call: the run that arrives while the other is in flight waits for its own turn', function () {
        $config = serialConfig();
        $second = new SyncAvatarConfig($config->account_id, $config->id);
        Http::fake(['*' => function () use ($second) {
            throughMiddleware($second);

            return Http::response([], 200);
        }]);

        throughMiddleware(new SyncAvatarConfig($config->account_id, $config->id));

        Http::assertSentCount(1);
        Queue::assertPushed(SyncAvatarConfig::class, 1);
        expect($config->refresh()->sync_status)->toBe(SyncStatus::Synced);
    });

    it('run one after the other: the copy that waited does its own call once the first is done', function () {
        $config = serialConfig();
        Http::fake(['*' => Http::response([], 200)]);

        throughMiddleware(new SyncAvatarConfig($config->account_id, $config->id));
        throughMiddleware(new SyncAvatarConfig($config->account_id, $config->id));

        Http::assertSentCount(2);
        Queue::assertNothingPushed();
    });
});
