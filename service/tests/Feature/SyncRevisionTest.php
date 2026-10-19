<?php

use App\AvatarProviders\ProviderException;
use App\AvatarProviders\SyncStatus;
use App\Jobs\SyncAvatarConfig;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\LlmModel;
use App\Models\ProviderCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['avatar_configs:read', 'avatar_configs:write']]);
    inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'tv-key-123456']));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function revisionConfig(array $attributes = []): AvatarConfig
{
    return inAccount(test()->account, fn () => AvatarConfig::factory()->create([
        'provider' => 'tavus',
        'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1'],
        'persona' => ['llm_temperature' => 0.5],
        'sync_status' => SyncStatus::Pending,
        ...$attributes,
    ]));
}

/**
 * What a PATCH that commits while a run is in flight leaves behind: a newer revision, waiting for its own run.
 */
function newerChangeLands(AvatarConfig $config): void
{
    inAccount(test()->account, fn () => AvatarConfig::query()->whereKey($config->id)->update([
        'sync_revision' => $config->sync_revision + 1,
        'sync_status' => SyncStatus::Pending->value,
        'persona' => json_encode(['llm_temperature' => 0.9]),
    ]));
}

/**
 * @param  array<string, mixed>  $body
 */
function revisionCall(string $method, string $uri, array $body = []): TestResponse
{
    $headers = ['Authorization' => 'Bearer '.test()->raw];

    return $method === 'get' ? test()->getJson($uri, $headers) : test()->{$method.'Json'}($uri, $body, $headers);
}

describe('a run that is overtaken by a newer change', function () {
    it('leaves the config pending: it never marks the older state synced', function () {
        $config = revisionConfig();
        Http::fake(['*' => function () use ($config) {
            newerChangeLands($config);

            return Http::response([], 200);
        }]);

        SyncAvatarConfig::dispatchSync($config->account_id, $config->id, $config->sync_revision);

        $config->refresh();
        expect($config->sync_status)->toBe(SyncStatus::Pending)
            ->and($config->sync_revision)->toBe(1)
            ->and($config->synced_at)->toBeNull()
            ->and($config->persona)->toBe(['llm_temperature' => 0.9]);
        Http::assertSentCount(1);
    });

    it('does not record its failure over the newer state either', function () {
        $config = revisionConfig(['sync_error' => null]);
        Http::fake(['*' => function () use ($config) {
            newerChangeLands($config);

            return Http::response(['error' => 'x'], 500);
        }]);

        // The synchronous queue reports the failure to the job's failure hook, which records it, then rethrows.
        expect(fn () => SyncAvatarConfig::dispatchSync($config->account_id, $config->id, $config->sync_revision))->toThrow(ProviderException::class);

        $config->refresh();
        expect($config->sync_status)->toBe(SyncStatus::Pending)->and($config->sync_error)->toBeNull();
    });

    it('makes no provider call when the config has already moved on before the run starts', function () {
        $config = revisionConfig(['sync_revision' => 4]);
        Http::fake();

        SyncAvatarConfig::dispatchSync($config->account_id, $config->id, 3);

        Http::assertNothingSent();
        expect($config->refresh()->sync_status)->toBe(SyncStatus::Pending);
    });

    it('still settles a run of the current revision', function () {
        $config = revisionConfig(['sync_revision' => 4]);
        Http::fake(['*' => Http::response([], 200)]);

        SyncAvatarConfig::dispatchSync($config->account_id, $config->id, 4);

        expect($config->refresh()->sync_status)->toBe(SyncStatus::Synced)->and($config->sync_revision)->toBe(4);
    });

    it('does not let a stale run settle a config that has since needed nothing', function () {
        $config = revisionConfig();
        Http::fake(['*' => function () use ($config) {
            inAccount(test()->account, fn () => AvatarConfig::query()->whereKey($config->id)->update(['sync_revision' => 1, 'sync_status' => SyncStatus::NotRequired->value]));

            return Http::response([], 200);
        }]);

        SyncAvatarConfig::dispatchSync($config->account_id, $config->id, 0);

        expect($config->refresh()->sync_status)->toBe(SyncStatus::NotRequired)->and($config->synced_at)->toBeNull();
    });
});

describe('the revision', function () {
    beforeEach(fn () => Queue::fake());

    it('starts at zero and is carried by the job of a create', function () {
        $response = revisionCall('post', '/v1/avatar-configs', ['name' => 'T', 'provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p']])->assertCreated();

        expect(AvatarConfig::withoutGlobalScopes()->find($response->json('id'))->sync_revision)->toBe(0);
        Queue::assertPushed(SyncAvatarConfig::class, fn (SyncAvatarConfig $job) => $job->revision === 0);
    });

    it('moves up with each change to what is synchronized, and the job carries the new one', function () {
        $config = revisionConfig(['sync_status' => SyncStatus::Synced]);

        revisionCall('patch', "/v1/avatar-configs/{$config->id}", ['persona' => ['llm_temperature' => 0.7]])->assertOk();
        revisionCall('patch', "/v1/avatar-configs/{$config->id}", ['config' => ['replica_id' => 'r_2', 'persona_id' => 'p_1']])->assertOk();

        expect($config->refresh()->sync_revision)->toBe(2);
        Queue::assertPushed(SyncAvatarConfig::class, fn (SyncAvatarConfig $job) => $job->revision === 1);
        Queue::assertPushed(SyncAvatarConfig::class, fn (SyncAvatarConfig $job) => $job->revision === 2);
    });

    it('moves up even when the change leaves nothing to synchronize, so a run in flight is overtaken', function () {
        LlmModel::factory()->create(['id' => 'gem-1']);
        $config = revisionConfig(['provider' => 'heygen', 'config' => ['avatarId' => 'a'], 'persona' => null, 'llm_model_id' => 'gem-1', 'sync_status' => SyncStatus::Pending]);

        revisionCall('patch', "/v1/avatar-configs/{$config->id}", ['llm_model_id' => null])->assertOk();

        expect($config->refresh()->sync_status)->toBe(SyncStatus::NotRequired)->and($config->sync_revision)->toBe(1);
        Queue::assertNothingPushed();
    });

    it('stays put for a change that does not touch it', function () {
        $config = revisionConfig(['sync_status' => SyncStatus::Synced]);

        revisionCall('patch', "/v1/avatar-configs/{$config->id}", ['name' => 'Renamed'])->assertOk();
        revisionCall('patch', "/v1/avatar-configs/{$config->id}", ['persona' => ['llm_temperature' => 0.5]])->assertOk();

        expect($config->refresh()->sync_revision)->toBe(0);
        Queue::assertNothingPushed();
    });

    it('moves up on a manual sync and the job carries it', function () {
        $config = revisionConfig(['sync_status' => SyncStatus::Failed, 'sync_revision' => 6]);

        revisionCall('post', "/v1/avatar-configs/{$config->id}/sync")->assertStatus(202);

        expect($config->refresh()->sync_revision)->toBe(7);
        Queue::assertPushed(SyncAvatarConfig::class, fn (SyncAvatarConfig $job) => $job->revision === 7);
    });

    it('moves up atomically: a change that commits between this request reading the config and writing it is not overwritten', function () {
        $config = revisionConfig(['sync_status' => SyncStatus::Synced]);
        // A concurrent request commits its own revision just before this request's write.
        AvatarConfig::saving(function (AvatarConfig $saving) {
            AvatarConfig::withoutGlobalScopes()->whereKey($saving->id)->increment('sync_revision');
        });

        revisionCall('patch', "/v1/avatar-configs/{$config->id}", ['persona' => ['llm_temperature' => 0.7]])->assertOk();

        expect($config->refresh()->sync_revision)->toBe(2);
        Queue::assertPushed(SyncAvatarConfig::class, fn (SyncAvatarConfig $job) => $job->revision === 2);
    });

    it('moves up atomically on a manual sync too', function () {
        $config = revisionConfig(['sync_status' => SyncStatus::Failed]);
        AvatarConfig::saving(function (AvatarConfig $saving) {
            AvatarConfig::withoutGlobalScopes()->whereKey($saving->id)->increment('sync_revision');
        });

        revisionCall('post', "/v1/avatar-configs/{$config->id}/sync")->assertStatus(202);

        expect($config->refresh()->sync_revision)->toBe(2);
        Queue::assertPushed(SyncAvatarConfig::class, fn (SyncAvatarConfig $job) => $job->revision === 2);
    });

    it('is not part of the resource', function () {
        $config = revisionConfig();

        expect(revisionCall('get', "/v1/avatar-configs/{$config->id}")->assertOk()->json())->not->toHaveKey('sync_revision');
    });
});
