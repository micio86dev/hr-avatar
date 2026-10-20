<?php

use App\AvatarProviders\SyncStatus;
use App\Jobs\SyncAvatarConfig;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\LlmModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Testing\TestResponse;

const AVATAR_CONFIG_MEMBERS = ['id', 'name', 'provider', 'config', 'persona', 'llm_model_id', 'voice', 'external_ref', 'sync_status', 'sync_error', 'synced_at', 'created_at', 'updated_at'];

beforeEach(function () {
    Queue::fake();
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['avatar_configs:read', 'avatar_configs:write']]);
    [, $this->readOnly] = issueKey($this->account, ['scopes' => ['avatar_configs:read']]);
    [, $this->writeOnly] = issueKey($this->account, ['scopes' => ['avatar_configs:write']]);
    LlmModel::factory()->create(['id' => 'gem-1']);
    LlmModel::factory()->create(['id' => 'old-1', 'enabled' => false]);
});

/**
 * @param  array<string, mixed>  $body
 */
function avcCall(string $method, string $uri, array $body = [], ?string $raw = null): TestResponse
{
    $headers = ['Authorization' => 'Bearer '.($raw ?? test()->raw)];

    return $method === 'get' ? test()->getJson($uri, $headers) : test()->{$method.'Json'}($uri, $body, $headers);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function avcMake(array $attributes = [], ?Account $account = null): AvatarConfig
{
    return inAccount($account ?? test()->account, fn () => AvatarConfig::factory()->create($attributes));
}

/**
 * @return array<string, mixed>
 */
function avcTavusBody(array $overrides = []): array
{
    return ['name' => 'Tavus', 'provider' => 'tavus', 'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1'], ...$overrides];
}

describe('POST /v1/avatar-configs', function () {
    it('creates a Mock config that needs no sync', function () {
        $response = avcCall('post', '/v1/avatar-configs', ['name' => 'Greeter', 'provider' => 'mock'])->assertCreated();

        expect($response->json('id'))->toMatch('/^avc_[0-9a-z]{26}$/')
            ->and(array_keys($response->json()))->toBe(AVATAR_CONFIG_MEMBERS)
            ->and($response->json('sync_status'))->toBe('not_required')
            ->and($response->json('provider'))->toBe('mock')
            ->and($response->json('persona'))->toBeNull()
            ->and($response->json('voice'))->toBeNull()
            ->and($response->json('llm_model_id'))->toBeNull()
            ->and($response->json('sync_error'))->toBeNull()
            ->and($response->json('synced_at'))->toBeNull()
            ->and($response->getContent())->toContain('"config":{}');
    });

    it('stores every member and stamps the caller as the owner', function () {
        $response = avcCall('post', '/v1/avatar-configs', avcTavusBody([
            'persona' => ['llm_temperature' => 0.4],
            'llm_model_id' => 'gem-1',
            'voice' => ['engine' => 'cartesia', 'voice_id' => 'v-1'],
            'external_ref' => 'tenant-7',
        ]))->assertCreated();

        $stored = inAccount($this->account, fn () => AvatarConfig::findOrFail($response->json('id')));

        expect($stored->account_id)->toBe($this->account->id)
            ->and($stored->config)->toEqual(['replica_id' => 'r_1', 'persona_id' => 'p_1'])
            ->and($stored->persona)->toBe(['llm_temperature' => 0.4])
            ->and($stored->llm_model_id)->toBe('gem-1')
            ->and($stored->voice)->toEqual(['engine' => 'cartesia', 'voice_id' => 'v-1'])
            ->and($stored->external_ref)->toBe('tenant-7')
            ->and($response->json('voice'))->toEqual(['engine' => 'cartesia', 'voice_id' => 'v-1']);
    });

    it('starts pending when the provider needs a sync and not required when it does not', function (array $body, string $status) {
        avcCall('post', '/v1/avatar-configs', $body)->assertCreated()->assertJsonPath('sync_status', $status);
    })->with([
        'tavus' => [fn () => avcTavusBody(), 'pending'],
        'heygen plain' => [['name' => 'H', 'provider' => 'heygen', 'config' => ['avatarId' => 'a']], 'not_required'],
        'heygen with a model' => [['name' => 'H', 'provider' => 'heygen', 'config' => ['avatarId' => 'a'], 'llm_model_id' => 'gem-1'], 'pending'],
        'heygen with an external voice' => [['name' => 'H', 'provider' => 'heygen', 'config' => ['avatarId' => 'a'], 'voice' => ['engine' => 'elevenlabs', 'voice_id' => 'v']], 'pending'],
        'heygen with a stock voice' => [['name' => 'H', 'provider' => 'heygen', 'config' => ['avatarId' => 'a'], 'voice' => ['engine' => 'provider', 'voice_id' => 'stock']], 'not_required'],
        'mock with a model' => [['name' => 'M', 'provider' => 'mock', 'llm_model_id' => 'gem-1'], 'not_required'],
    ]);

    it('validates the config against the provider specification and names the field', function (array $body, array $errors) {
        $response = avcCall('post', '/v1/avatar-configs', $body)->assertStatus(422);

        expect($response->json('code'))->toBe('validation_failed')
            ->and(array_keys($response->json('errors')))->toBe($errors)
            ->and(inAccount($this->account, fn () => AvatarConfig::count()))->toBe(0);
    })->with([
        'unknown key' => [fn () => avcTavusBody(['config' => ['replica_id' => 'r', 'persona_id' => 'p', 'faceId' => 'x']]), ['config.faceId']],
        'missing identifier' => [fn () => avcTavusBody(['config' => ['replica_id' => 'r']]), ['config.persona_id']],
        'no config at all' => [['name' => 'T', 'provider' => 'tavus'], ['config.replica_id', 'config.persona_id']],
        'wrong type' => [fn () => avcTavusBody(['config' => ['replica_id' => 'r', 'persona_id' => 'p', 'participant_left_timeout' => 'soon']]), ['config.participant_left_timeout']],
        'mock with a key' => [['name' => 'M', 'provider' => 'mock', 'config' => ['x' => 1]], ['config.x']],
        'unknown persona knob' => [fn () => avcTavusBody(['persona' => ['llm_model' => 'x']]), ['persona.llm_model']],
        'persona out of range' => [fn () => avcTavusBody(['persona' => ['llm_temperature' => 9]]), ['persona.llm_temperature']],
        'voice setting without a voice' => [['name' => 'H', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'voiceSpeed' => 1]], ['config.voiceSpeed']],
    ]);

    it('puts a field error under its own key with a fixed sentence that does not quote the value', function () {
        $response = avcCall('post', '/v1/avatar-configs', avcTavusBody(['config' => ['replica_id' => 'r', 'persona_id' => 'p', 'secret_looking' => 'sk-123']]))->assertStatus(422);

        expect($response->json('errors'))->toBe(['config.secret_looking' => ['This setting is not recognised for this provider.']])
            ->and($response->getContent())->not->toContain('sk-123');
    });

    it('accepts an empty config for Mock whether it is omitted or empty', function (array $body) {
        avcCall('post', '/v1/avatar-configs', ['name' => 'M', 'provider' => 'mock', ...$body])->assertCreated();
    })->with([
        'omitted' => [[]],
        'empty' => [['config' => []]],
    ]);

    it('rejects a provider that is not registered', function (mixed $provider) {
        $response = avcCall('post', '/v1/avatar-configs', ['name' => 'X', 'provider' => $provider])->assertStatus(422);

        expect(array_keys($response->json('errors')))->toBe(['provider']);
    })->with(['unknown' => ['nope'], 'other case' => ['Tavus'], 'number' => [5]]);

    it('rejects a body that is missing or malformed on its own members', function (array $body, string $field) {
        $response = avcCall('post', '/v1/avatar-configs', $body)->assertStatus(422);

        expect(array_keys($response->json('errors')))->toContain($field);
    })->with([
        'no name' => [['provider' => 'mock'], 'name'],
        'empty name' => [['name' => '', 'provider' => 'mock'], 'name'],
        'long name' => [['name' => str_repeat('x', 121), 'provider' => 'mock'], 'name'],
        'no provider' => [['name' => 'x'], 'provider'],
        'config not an object' => [['name' => 'x', 'provider' => 'mock', 'config' => 'text'], 'config'],
        'persona not an object' => [['name' => 'x', 'provider' => 'mock', 'persona' => 'text'], 'persona'],
        'long reference' => [['name' => 'x', 'provider' => 'mock', 'external_ref' => str_repeat('r', 256)], 'external_ref'],
    ]);

    it('accepts the longest name and reference', function () {
        avcCall('post', '/v1/avatar-configs', ['name' => str_repeat('x', 120), 'provider' => 'mock', 'external_ref' => str_repeat('r', 255)])->assertCreated();
    });

    it('requires the language model to be an enabled catalogue entry', function (string $id) {
        $response = avcCall('post', '/v1/avatar-configs', ['name' => 'M', 'provider' => 'mock', 'llm_model_id' => $id])->assertStatus(422);

        expect(array_keys($response->json('errors')))->toBe(['llm_model_id']);
    })->with(['disabled' => ['old-1'], 'unknown' => ['nope']]);

    it('binds an enabled model', function () {
        avcCall('post', '/v1/avatar-configs', ['name' => 'M', 'provider' => 'mock', 'llm_model_id' => 'gem-1'])->assertCreated()->assertJsonPath('llm_model_id', 'gem-1');
    });

    it('validates the voice binding', function (array $voice, array $errors) {
        $response = avcCall('post', '/v1/avatar-configs', avcTavusBody(['voice' => $voice]))->assertStatus(422);

        expect(array_keys($response->json('errors')))->toBe($errors);
    })->with([
        'empty' => [[], ['voice']],
        'engine only' => [['engine' => 'cartesia'], ['voice']],
        'unknown engine' => [['engine' => 'azure', 'voice_id' => 'v'], ['voice.engine']],
        'empty id' => [['engine' => 'cartesia', 'voice_id' => ''], ['voice.voice_id']],
        'long id' => [['engine' => 'cartesia', 'voice_id' => str_repeat('v', 129)], ['voice.voice_id']],
    ]);

    it('accepts a voice id of 1 and of 128 characters', function (string $id) {
        avcCall('post', '/v1/avatar-configs', avcTavusBody(['voice' => ['engine' => 'elevenlabs', 'voice_id' => $id]]))->assertCreated();
    })->with([['v'], [str_repeat('v', 128)]]);

    it('accepts an external voice on a provider that supports it and refuses it on one that does not', function () {
        avcCall('post', '/v1/avatar-configs', avcTavusBody(['voice' => ['engine' => 'cartesia', 'voice_id' => 'v-1']]))->assertCreated();

        $response = avcCall('post', '/v1/avatar-configs', ['name' => 'M', 'provider' => 'mock', 'voice' => ['engine' => 'elevenlabs', 'voice_id' => 'v']])->assertStatus(422);
        expect(array_keys($response->json('errors')))->toBe(['voice.engine']);
    });

    it('ignores members a client may not choose', function () {
        $other = Account::factory()->create();

        $response = avcCall('post', '/v1/avatar-configs', [
            'name' => 'M', 'provider' => 'mock', 'account_id' => $other->id, 'id' => 'avc_chosen', 'sync_status' => 'synced',
            'provider_state' => ['x' => 1], 'sync_error' => 'x', 'deleted_at' => now()->toIso8601String(),
        ])->assertCreated();

        $stored = inAccount($this->account, fn () => AvatarConfig::findOrFail($response->json('id')));
        expect($stored->account_id)->toBe($this->account->id)
            ->and($stored->id)->not->toBe('avc_chosen')
            ->and($stored->sync_status)->toBe(SyncStatus::NotRequired)
            ->and($stored->provider_state)->toBe([])
            ->and($stored->deleted_at)->toBeNull();
    });
});

describe('GET /v1/avatar-configs', function () {
    it('lists the account configs newest first as a page', function () {
        $old = avcMake(['name' => 'old', 'created_at' => now()->subHour()]);
        $new = avcMake(['name' => 'new']);

        $response = avcCall('get', '/v1/avatar-configs')->assertOk();

        expect(array_keys($response->json()))->toBe(['data', 'next_cursor'])
            ->and(array_column($response->json('data'), 'id'))->toBe([$new->id, $old->id])
            ->and($response->json('next_cursor'))->toBeNull()
            ->and(array_keys($response->json('data.0')))->toBe(AVATAR_CONFIG_MEMBERS);
    });

    it('pages with limit and cursor without losing or repeating an item', function () {
        foreach (range(1, 5) as $i) {
            avcMake(['created_at' => now()->subMinutes($i)]);
        }

        $first = avcCall('get', '/v1/avatar-configs?limit=2')->assertOk();
        $second = avcCall('get', '/v1/avatar-configs?limit=2&cursor='.$first->json('next_cursor'))->assertOk();
        $third = avcCall('get', '/v1/avatar-configs?limit=2&cursor='.$second->json('next_cursor'))->assertOk();

        $ids = [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id'), ...array_column($third->json('data'), 'id')];

        expect($ids)->toHaveCount(5)->and(array_unique($ids))->toHaveCount(5)
            ->and($third->json('next_cursor'))->toBeNull();
    });

    it('omits another account and a deleted config', function () {
        $mine = avcMake();
        avcMake(account: Account::factory()->create());
        $gone = avcMake();
        avcCall('delete', "/v1/avatar-configs/{$gone->id}")->assertNoContent();

        expect(array_column(avcCall('get', '/v1/avatar-configs')->json('data'), 'id'))->toBe([$mine->id]);
    });

    it('filters by an exact external_ref before the page is cut', function () {
        $wanted = [];
        foreach (range(1, 3) as $i) {
            $wanted[] = avcMake(['external_ref' => 'tenant-a', 'created_at' => now()->subMinutes($i * 10)])->id;
        }
        foreach (range(1, 4) as $i) {
            avcMake(['external_ref' => 'tenant-b', 'created_at' => now()->subMinutes($i)]);
        }
        avcMake(['external_ref' => 'tenant-a-2']);
        avcMake(['external_ref' => null]);

        $first = avcCall('get', '/v1/avatar-configs?external_ref=tenant-a&limit=2')->assertOk();
        $second = avcCall('get', '/v1/avatar-configs?external_ref=tenant-a&limit=2&cursor='.$first->json('next_cursor'))->assertOk();

        expect(array_column($first->json('data'), 'id'))->toBe([$wanted[0], $wanted[1]])
            ->and(array_column($second->json('data'), 'id'))->toBe([$wanted[2]])
            ->and($second->json('next_cursor'))->toBeNull();
    });

    it('matches the reference exactly: no case folding, no wildcard', function (string $query) {
        avcMake(['external_ref' => 'Tenant_A']);

        expect(avcCall('get', '/v1/avatar-configs?external_ref='.urlencode($query))->json('data'))->toBe([]);
    })->with(['tenant_a', 'Tenant', 'Tenant%', 'Tenant_', '%']);

    it('never reaches another account through the filter', function () {
        avcMake(['external_ref' => 'shared'], Account::factory()->create());

        expect(avcCall('get', '/v1/avatar-configs?external_ref=shared')->json('data'))->toBe([]);
    });

    it('rejects an empty or oversized external_ref filter', function (string $query) {
        avcCall('get', '/v1/avatar-configs?'.$query)->assertStatus(422);
    })->with(['empty' => ['external_ref='], 'long' => ['external_ref='.str_repeat('r', 256)]]);

    it('keeps the pagination limits', function () {
        avcCall('get', '/v1/avatar-configs?limit=101')->assertStatus(422);
        avcCall('get', '/v1/avatar-configs?cursor=garbage')->assertStatus(422);
    });
});

describe('GET /v1/avatar-configs/{id}', function () {
    it('shows one config with the members of the resource and none of the internals', function () {
        $config = avcMake(['config' => [], 'provider_state' => ['llm_configuration_id' => 'llm_secretish'], 'sync_error' => 'Provider request failed (status 500)']);

        $response = avcCall('get', "/v1/avatar-configs/{$config->id}")->assertOk();

        expect(array_keys($response->json()))->toBe(AVATAR_CONFIG_MEMBERS)
            ->and($response->json('sync_error'))->toBe('Provider request failed (status 500)')
            ->and($response->getContent())->not->toContain('provider_state')->not->toContain('llm_secretish')->not->toContain('account_id')->not->toContain('deleted_at');
    });

    it('renders an empty settings object as an object, not a list', function () {
        $config = avcMake(['config' => [], 'persona' => [], 'voice' => null]);

        $content = avcCall('get', "/v1/avatar-configs/{$config->id}")->assertOk()->getContent();

        expect($content)->toContain('"config":{}')->toContain('"persona":{}')->toContain('"voice":null');
    });

    it('renders timestamps as UTC ISO 8601', function () {
        $config = avcMake(['sync_status' => SyncStatus::Synced, 'synced_at' => '2026-10-19 10:00:00']);

        $response = avcCall('get', "/v1/avatar-configs/{$config->id}")->assertOk();

        expect($response->json('synced_at'))->toBe('2026-10-19T10:00:00Z')
            ->and($response->json('created_at'))->toMatch('/Z$/');
    });
});

describe('PATCH /v1/avatar-configs/{id}', function () {
    it('renames without touching the sync state', function () {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'sync_status' => SyncStatus::Synced, 'synced_at' => now()]);

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['name' => 'Renamed', 'external_ref' => 'ref-2'])->assertOk()
            ->assertJsonPath('name', 'Renamed')->assertJsonPath('external_ref', 'ref-2')->assertJsonPath('sync_status', 'synced');

        expect($config->refresh()->synced_at)->not->toBeNull();
    });

    it('does not change the provider', function () {
        $config = avcMake();

        $response = avcCall('patch', "/v1/avatar-configs/{$config->id}", ['provider' => 'tavus'])->assertStatus(422);

        expect(array_keys($response->json('errors')))->toBe(['provider'])
            ->and($config->refresh()->provider)->toBe('mock');
    });

    it('accepts the provider it already has', function () {
        $config = avcMake();

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['provider' => 'mock', 'name' => 'Same'])->assertOk();
    });

    it('resets a synced config to pending when what the provider holds changes', function (array $patch) {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'sync_status' => SyncStatus::Synced, 'synced_at' => now()]);

        avcCall('patch', "/v1/avatar-configs/{$config->id}", $patch)->assertOk()->assertJsonPath('sync_status', 'pending');

        expect($config->refresh()->sync_status)->toBe(SyncStatus::Pending);
    })->with([
        'config' => [['config' => ['replica_id' => 'r2', 'persona_id' => 'p']]],
        'persona' => [['persona' => ['llm_temperature' => 0.2]]],
        'model' => [['llm_model_id' => 'gem-1']],
        'voice' => [['voice' => ['engine' => 'cartesia', 'voice_id' => 'v']]],
    ]);

    it('keeps the sync state when the patch repeats what is stored', function () {
        $config = avcMake([
            'provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'persona' => ['llm_temperature' => 0.2],
            'voice' => ['engine' => 'cartesia', 'voice_id' => 'v'], 'sync_status' => SyncStatus::Synced,
        ]);

        avcCall('patch', "/v1/avatar-configs/{$config->id}", [
            'config' => ['persona_id' => 'p', 'replica_id' => 'r'], 'persona' => ['llm_temperature' => 0.2], 'voice' => ['voice_id' => 'v', 'engine' => 'cartesia'],
        ])->assertOk()->assertJsonPath('sync_status', 'synced');
    });

    it('re-evaluates whether a sync is needed: a LiveAvatar config that drops its model needs none', function () {
        $config = avcMake(['provider' => 'heygen', 'config' => ['avatarId' => 'a'], 'llm_model_id' => 'gem-1', 'sync_status' => SyncStatus::Synced]);

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['llm_model_id' => null])->assertOk()
            ->assertJsonPath('sync_status', 'not_required')->assertJsonPath('llm_model_id', null);
    });

    it('moves a LiveAvatar config to pending when it gains a model', function () {
        $config = avcMake(['provider' => 'heygen', 'config' => ['avatarId' => 'a'], 'sync_status' => SyncStatus::NotRequired]);

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['llm_model_id' => 'gem-1'])->assertOk()->assertJsonPath('sync_status', 'pending');
    });

    it('leaves a Mock config not required whatever changes', function () {
        $config = avcMake();

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['llm_model_id' => 'gem-1', 'persona' => null])->assertOk()->assertJsonPath('sync_status', 'not_required');
    });

    it('clears a previous sync error and time when it goes back to pending', function () {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'sync_status' => SyncStatus::Failed, 'sync_error' => 'Provider request failed (status 500)']);

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['persona' => ['llm_temperature' => 1]])->assertOk()
            ->assertJsonPath('sync_status', 'pending')->assertJsonPath('sync_error', null);
    });

    it('validates the config that would result, not only the members sent', function () {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p', 'tts_model_name' => 'sonic-3'], 'voice' => ['engine' => 'cartesia', 'voice_id' => 'v']]);

        $response = avcCall('patch', "/v1/avatar-configs/{$config->id}", ['voice' => ['engine' => 'elevenlabs', 'voice_id' => 'v']])->assertStatus(422);

        expect(array_keys($response->json('errors')))->toBe(['config.tts_model_name'])
            ->and($config->refresh()->voice)->toEqual(['engine' => 'cartesia', 'voice_id' => 'v']);
    });

    it('refuses to remove the voice a stored setting depends on', function () {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p', 'tts_model_name' => 'sonic-3'], 'voice' => ['engine' => 'cartesia', 'voice_id' => 'v']]);

        $response = avcCall('patch', "/v1/avatar-configs/{$config->id}", ['voice' => null])->assertStatus(422);

        expect(array_keys($response->json('errors')))->toBe(['config.tts_model_name']);
    });

    it('validates a replaced config against the provider specification', function () {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p']]);

        $response = avcCall('patch', "/v1/avatar-configs/{$config->id}", ['config' => ['replica_id' => 'r']])->assertStatus(422);

        expect(array_keys($response->json('errors')))->toBe(['config.persona_id']);
    });

    it('rejects a disabled model and a null name', function () {
        $config = avcMake();

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['llm_model_id' => 'old-1'])->assertStatus(422);
        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['name' => null])->assertStatus(422);
    });

    it('ignores members a client may not choose', function () {
        $config = avcMake();

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['account_id' => Account::factory()->create()->id, 'sync_status' => 'synced', 'id' => 'avc_x', 'name' => 'ok'])->assertOk();

        $config->refresh();
        expect($config->account_id)->toBe($this->account->id)->and($config->sync_status)->toBe(SyncStatus::NotRequired);
    });
});

describe('DELETE /v1/avatar-configs/{id}', function () {
    it('deletes softly: gone from the list and the show, kept in the table', function () {
        $config = avcMake();

        avcCall('delete', "/v1/avatar-configs/{$config->id}")->assertNoContent();

        avcCall('get', "/v1/avatar-configs/{$config->id}")->assertNotFound();
        expect(avcCall('get', '/v1/avatar-configs')->json('data'))->toBe([])
            ->and(inAccount($this->account, fn () => AvatarConfig::withTrashed()->find($config->id)?->deleted_at))->not->toBeNull()
            ->and(DB::table('avatar_configs')->where('id', $config->id)->exists())->toBeTrue();
    });

    it('answers 404 for one that is already deleted', function () {
        $config = avcMake();
        avcCall('delete', "/v1/avatar-configs/{$config->id}")->assertNoContent();

        avcCall('delete', "/v1/avatar-configs/{$config->id}")->assertNotFound();
    });

    it('cannot be patched once deleted', function () {
        $config = avcMake();
        avcCall('delete', "/v1/avatar-configs/{$config->id}")->assertNoContent();

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['name' => 'x'])->assertNotFound();
    });
});

describe('sync dispatch', function () {
    it('queues a sync for a config that starts pending, and for none that does not', function (array $body, bool $queued) {
        $id = avcCall('post', '/v1/avatar-configs', $body)->assertCreated()->json('id');

        $queued ? Queue::assertPushed(SyncAvatarConfig::class, fn (SyncAvatarConfig $job) => $job->accountId === $this->account->id && $job->avatarConfigId === $id) : Queue::assertNothingPushed();
    })->with([
        'tavus' => [fn () => avcTavusBody(), true],
        'heygen with a model' => [['name' => 'H', 'provider' => 'heygen', 'config' => ['avatarId' => 'a'], 'llm_model_id' => 'gem-1'], true],
        'heygen plain' => [['name' => 'H', 'provider' => 'heygen', 'config' => ['avatarId' => 'a']], false],
        'mock' => [['name' => 'M', 'provider' => 'mock'], false],
    ]);

    it('queues a sync when a patch really changes what the provider holds, and only then', function (array $patch, bool $queued) {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'sync_status' => SyncStatus::Synced]);

        avcCall('patch', "/v1/avatar-configs/{$config->id}", $patch)->assertOk();

        $queued ? Queue::assertPushed(SyncAvatarConfig::class, 1) : Queue::assertNothingPushed();
    })->with([
        'persona' => [['persona' => ['llm_temperature' => 1]], true],
        'config' => [['config' => ['replica_id' => 'r2', 'persona_id' => 'p']], true],
        'name only' => [['name' => 'Renamed'], false],
        'same config, other key order' => [['config' => ['persona_id' => 'p', 'replica_id' => 'r']], false],
    ]);

    // Review rv16 R3-001: 1 and 1.0 are the same number; repeating what is stored in another spelling is not a change.
    // Not reproducible: the cast writes the sent value as JSON before it is compared, and PHP writes 1.0 as 1, so the
    // spellings meet there. Pinned, because the comparison would otherwise look at an int and a float. The body is sent
    // as text: the test client would write 1.0 as 1 itself.
    it('treats a number repeated in another spelling as no change: the state stays and nothing is queued', function (int|float $stored, string $sent) {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'persona' => ['llm_temperature' => $stored], 'sync_status' => SyncStatus::Synced]);

        $this->call('PATCH', "/v1/avatar-configs/{$config->id}", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{"persona":{"llm_temperature":'.$sent.'}}')
            ->assertOk()->assertJsonPath('sync_status', 'synced');

        Queue::assertNothingPushed();
        expect($config->refresh()->sync_revision)->toBe(0);
    })->with([
        'integer stored, float sent' => [1, '1.0'],
        'float stored, integer sent' => [1.0, '1'],
    ]);

    it('still queues a sync for a number that really differs', function () {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'persona' => ['llm_temperature' => 1], 'sync_status' => SyncStatus::Synced]);

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['persona' => ['llm_temperature' => 1.5]])->assertOk()->assertJsonPath('sync_status', 'pending');

        Queue::assertPushed(SyncAvatarConfig::class, 1);
    });

    it('queues nothing for a patch that settles a config as not required', function () {
        $config = avcMake(['provider' => 'heygen', 'config' => ['avatarId' => 'a'], 'llm_model_id' => 'gem-1', 'sync_status' => SyncStatus::Synced]);

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['llm_model_id' => null])->assertOk();

        Queue::assertNothingPushed();
    });

    it('queues the sync that removes a stale LLM configuration when the model is unbound', function () {
        $config = avcMake(['provider' => 'heygen', 'config' => ['avatarId' => 'a'], 'llm_model_id' => 'gem-1', 'provider_state' => ['llm_configuration_id' => 'llm-1'], 'sync_status' => SyncStatus::Synced]);

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['llm_model_id' => null])->assertOk()->assertJsonPath('sync_status', 'pending');

        Queue::assertPushed(SyncAvatarConfig::class, 1);
    });
});

/** A queue that refuses every push, as one that is down does. */
function avcDownQueue(): QueueFake
{
    $queue = new class(app()) extends QueueFake
    {
        public function push($job, $data = '', $queue = null)
        {
            throw new RuntimeException('queue is down');
        }
    };
    Queue::swap($queue);

    return $queue;
}

// Review rv17 R3-006: the row is saved before the job is pushed, so a push that fails must not leave a config `pending`
// that nothing will ever sync, nor answer an error for a config that exists.
describe('a queue that is down', function () {
    it('leaves a created config failed with a fixed reason the caller can retry, and answers 201', function () {
        avcDownQueue();

        $response = avcCall('post', '/v1/avatar-configs', avcTavusBody())->assertCreated();

        $response->assertJsonPath('sync_status', 'failed')->assertJsonPath('sync_error', 'Synchronization could not be queued.');
        Queue::swap(new QueueFake(app()));
        avcCall('post', '/v1/avatar-configs/'.$response->json('id').'/sync')->assertStatus(202)->assertJsonPath('sync_status', 'pending');
        Queue::assertPushed(SyncAvatarConfig::class, 1);
    });

    it('does the same for a patch that changes what the provider holds, and for a manual sync', function () {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'sync_status' => SyncStatus::Synced]);
        avcDownQueue();

        avcCall('patch', "/v1/avatar-configs/{$config->id}", ['persona' => ['llm_temperature' => 1]])->assertOk()
            ->assertJsonPath('sync_status', 'failed')->assertJsonPath('sync_error', 'Synchronization could not be queued.');
        avcCall('post', "/v1/avatar-configs/{$config->id}/sync")->assertStatus(202)
            ->assertJsonPath('sync_status', 'failed')->assertJsonPath('sync_error', 'Synchronization could not be queued.');
    });

    it('logs the kind of failure and not its message', function () {
        $logs = captureLogs();
        avcDownQueue();

        avcCall('post', '/v1/avatar-configs', avcTavusBody())->assertCreated();

        $everything = implode("\n", $logs->getArrayCopy());
        expect($everything)->toContain('Avatar config sync could not be queued')->and($everything)->toContain(RuntimeException::class)->and($everything)->not->toContain('queue is down');
    });
});

describe('POST /v1/avatar-configs/{id}/sync', function () {
    it('queues a sync of a failed config and answers 202 with it pending', function () {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'sync_status' => SyncStatus::Failed, 'sync_error' => 'Provider request failed (status 500)']);

        $response = avcCall('post', "/v1/avatar-configs/{$config->id}/sync")->assertStatus(202);

        expect(array_keys($response->json()))->toBe(AVATAR_CONFIG_MEMBERS)
            ->and($response->json('sync_status'))->toBe('pending')
            ->and($response->json('sync_error'))->toBeNull()
            ->and($config->refresh()->sync_status)->toBe(SyncStatus::Pending);
        Queue::assertPushed(SyncAvatarConfig::class, fn (SyncAvatarConfig $job) => $job->avatarConfigId === $config->id);
    });

    it('syncs a synced config again', function () {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'sync_status' => SyncStatus::Synced]);

        avcCall('post', "/v1/avatar-configs/{$config->id}/sync")->assertStatus(202)->assertJsonPath('sync_status', 'pending');

        Queue::assertPushed(SyncAvatarConfig::class, 1);
    });

    it('refuses a config whose provider needs no objects: a Mock config is never synchronized', function () {
        $config = avcMake();

        $response = avcCall('post', "/v1/avatar-configs/{$config->id}/sync")->assertStatus(409);

        expect($response->json('code'))->toBe('invalid_state')->and($config->refresh()->sync_status)->toBe(SyncStatus::NotRequired);
        Queue::assertNothingPushed();
    });

    it('answers 404 to another account and for a deleted config', function () {
        $foreign = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p']], Account::factory()->create());
        $gone = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p']]);
        $gone->delete();

        avcCall('post', "/v1/avatar-configs/{$foreign->id}/sync")->assertNotFound();
        avcCall('post', "/v1/avatar-configs/{$gone->id}/sync")->assertNotFound();
        Queue::assertNothingPushed();
    });

    it('needs the write scope and a key', function () {
        $config = avcMake(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'sync_status' => SyncStatus::Failed]);

        $response = avcCall('post', "/v1/avatar-configs/{$config->id}/sync", raw: $this->readOnly)->assertStatus(403);
        expect($response->json('code'))->toBe('insufficient_scope');
        $this->postJson("/v1/avatar-configs/{$config->id}/sync")->assertStatus(401);

        expect($config->refresh()->sync_status)->toBe(SyncStatus::Failed);
        Queue::assertNothingPushed();
    });
});

describe('account isolation', function () {
    it('answers 404 to another account on show, patch and delete, and changes nothing', function (string $method) {
        $foreign = Account::factory()->create();
        $config = avcMake(['name' => 'theirs'], $foreign);

        $response = avcCall($method, "/v1/avatar-configs/{$config->id}", $method === 'patch' ? ['name' => 'taken'] : []);

        $response->assertNotFound();
        expect($response->json('code'))->toBe('not_found')
            ->and(inAccount($foreign, fn () => AvatarConfig::find($config->id)?->name))->toBe('theirs');
    })->with(['get', 'patch', 'delete']);

    it('does not list or find another account config by its id', function () {
        $foreign = avcMake([], Account::factory()->create());

        avcCall('get', "/v1/avatar-configs/{$foreign->id}")->assertNotFound();
        expect(avcCall('get', '/v1/avatar-configs')->json('data'))->toBe([]);
    });
});

describe('scopes and authentication', function () {
    it('lets a read-only key read and nothing else', function () {
        $config = avcMake();

        avcCall('get', '/v1/avatar-configs', raw: $this->readOnly)->assertOk();
        avcCall('get', "/v1/avatar-configs/{$config->id}", raw: $this->readOnly)->assertOk();

        foreach ([['post', '/v1/avatar-configs', ['name' => 'x', 'provider' => 'mock']], ['patch', "/v1/avatar-configs/{$config->id}", ['name' => 'x']], ['delete', "/v1/avatar-configs/{$config->id}", []]] as [$method, $uri, $body]) {
            $response = avcCall($method, $uri, $body, $this->readOnly)->assertStatus(403);
            expect($response->json('code'))->toBe('insufficient_scope');
        }

        expect(inAccount($this->account, fn () => AvatarConfig::count()))->toBe(1)->and($config->refresh()->name)->not->toBe('x');
    });

    it('lets a write-only key write and not read', function () {
        $created = avcCall('post', '/v1/avatar-configs', ['name' => 'x', 'provider' => 'mock'], $this->writeOnly)->assertCreated();

        avcCall('patch', '/v1/avatar-configs/'.$created->json('id'), ['name' => 'y'], $this->writeOnly)->assertOk();
        avcCall('get', '/v1/avatar-configs', raw: $this->writeOnly)->assertStatus(403);
        avcCall('get', '/v1/avatar-configs/'.$created->json('id'), raw: $this->writeOnly)->assertStatus(403);
        avcCall('delete', '/v1/avatar-configs/'.$created->json('id'), raw: $this->writeOnly)->assertNoContent();
    });

    it('requires authentication on every route', function () {
        $config = avcMake();

        $this->getJson('/v1/avatar-configs')->assertStatus(401);
        $this->postJson('/v1/avatar-configs', [])->assertStatus(401);
        $this->getJson("/v1/avatar-configs/{$config->id}")->assertStatus(401);
        $this->patchJson("/v1/avatar-configs/{$config->id}", [])->assertStatus(401);
        $this->deleteJson("/v1/avatar-configs/{$config->id}")->assertStatus(401);
    });
});
