<?php

use App\AvatarProviders\SyncStatus;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\LlmModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(fn () => $this->account = Account::factory()->create());

function makeConfig(array $attributes = [], ?Account $account = null): AvatarConfig
{
    return inAccount($account ?? test()->account, fn () => AvatarConfig::factory()->create($attributes));
}

/** Runs a statement that must fail inside a savepoint, so the test's transaction survives. */
function rejected(Closure $statement): bool
{
    try {
        DB::transaction($statement);
    } catch (QueryException) {
        return true;
    }

    return false;
}

function rawConfigRow(array $overrides = []): array
{
    return [
        'id' => 'avc_'.strtolower((string) Str::ulid()),
        'account_id' => test()->account->id,
        'name' => 'Raw', 'provider' => 'mock',
        'config' => '{}', 'provider_state' => '{}', 'sync_status' => 'not_required',
        'created_at' => now(), 'updated_at' => now(),
        ...$overrides,
    ];
}

it('creates a config with a prefixed id and the documented defaults', function () {
    $config = inAccount($this->account, fn () => AvatarConfig::create(['name' => 'Greeter', 'provider' => 'mock']))->fresh();

    expect($config->id)->toMatch('/^avc_[0-9a-z]{26}\z/')
        ->and($config->account_id)->toBe($this->account->id)
        ->and($config->sync_status)->toBe(SyncStatus::NotRequired)
        ->and($config->config)->toBe([])
        ->and($config->provider_state)->toBe([])
        ->and($config->persona)->toBeNull()
        ->and($config->voice)->toBeNull()
        ->and($config->llm_model_id)->toBeNull()
        ->and($config->external_ref)->toBeNull()
        ->and($config->sync_error)->toBeNull()
        ->and($config->synced_at)->toBeNull()
        ->and($config->deleted_at)->toBeNull();
});

// Review rv09 R3-001: the instance create() returns holds the table's defaults too, before anything reads it back.
it('holds the documented defaults on the instance create() returns, without reloading it', function () {
    $config = inAccount($this->account, fn () => AvatarConfig::create(['name' => 'Greeter', 'provider' => 'mock']));

    expect($config->sync_status)->toBe(SyncStatus::NotRequired)
        ->and($config->config)->toBe([])
        ->and($config->provider_state)->toBe([])
        ->and($config->sync_revision)->toBe(0);
});

it('round-trips the structured columns', function () {
    $config = makeConfig([
        'config' => ['replica_id' => 'r1', 'nested' => ['a' => [1, 2, 3]]],
        'persona' => ['name' => 'Ada', 'layers' => ['stt' => 'x']],
        'voice' => ['engine' => 'cartesia', 'voice_id' => 'v-123'],
        'provider_state' => ['llm_configuration_id' => 'cfg_1'],
        'external_ref' => 'host-record-42',
    ])->fresh();

    // jsonb does not keep key order, so the comparison ignores it.
    expect($config->config)->toEqual(['replica_id' => 'r1', 'nested' => ['a' => [1, 2, 3]]])
        ->and($config->persona)->toEqual(['name' => 'Ada', 'layers' => ['stt' => 'x']])
        ->and($config->voice)->toEqual(['engine' => 'cartesia', 'voice_id' => 'v-123'])
        ->and($config->provider_state)->toEqual(['llm_configuration_id' => 'cfg_1'])
        ->and($config->external_ref)->toBe('host-record-42');
});

it('stores an empty object as an object, never as a list', function () {
    $config = makeConfig(['config' => [], 'persona' => [], 'voice' => [], 'provider_state' => []]);

    $row = DB::table('avatar_configs')->where('id', $config->id)->first();

    expect($row->config)->toBe('{}')->and($row->persona)->toBe('{}')->and($row->voice)->toBe('{}')->and($row->provider_state)->toBe('{}');
});

it('refuses a sync status outside the four values', function (string $status, bool $accepted) {
    expect(rejected(fn () => DB::table('avatar_configs')->insert(rawConfigRow(['sync_status' => $status]))))->toBe(! $accepted);
})->with([
    'not_required' => ['not_required', true],
    'pending' => ['pending', true],
    'synced' => ['synced', true],
    'failed' => ['failed', true],
    'unknown' => ['done', false],
    'empty' => ['', false],
]);

it('refuses a structured column that is not a JSON object', function (string $column, string $value) {
    expect(rejected(fn () => DB::table('avatar_configs')->insert(rawConfigRow([$column => $value]))))->toBeTrue();
})->with([
    'a list config' => ['config', '[]'],
    'a scalar config' => ['config', '"x"'],
    'a list persona' => ['persona', '[1]'],
    'a scalar voice' => ['voice', '7'],
    'a list provider state' => ['provider_state', '[]'],
]);

it('accepts a null persona and voice', function () {
    expect(rejected(fn () => DB::table('avatar_configs')->insert(rawConfigRow(['persona' => null, 'voice' => null]))))->toBeFalse();
});

it('casts the sync status and the timestamps', function () {
    $config = makeConfig(['sync_status' => SyncStatus::Failed, 'sync_error' => 'Provider request failed (status 502)', 'synced_at' => now()])->fresh();

    expect($config->sync_status)->toBe(SyncStatus::Failed)
        ->and($config->synced_at)->toBeInstanceOf(Carbon::class)
        ->and($config->sync_error)->toBe('Provider request failed (status 502)');
});

it('soft-deletes: the row stays, queries stop seeing it', function () {
    $config = makeConfig();

    inAccount($this->account, fn () => $config->delete());

    expect(inAccount($this->account, fn () => AvatarConfig::count()))->toBe(0)
        ->and(inAccount($this->account, fn () => AvatarConfig::find($config->id)))->toBeNull()
        ->and(inAccount($this->account, fn () => AvatarConfig::withTrashed()->find($config->id)?->deleted_at))->not->toBeNull()
        ->and(DB::table('avatar_configs')->where('id', $config->id)->count())->toBe(1);
});

it('references an LLM model that cannot be removed while used, nor one that does not exist', function () {
    $model = LlmModel::factory()->create();
    $config = makeConfig(['llm_model_id' => $model->id]);

    expect($config->fresh()->llmModel->is($model))->toBeTrue()
        ->and(rejected(fn () => $model->delete()))->toBeTrue()
        ->and(rejected(fn () => DB::table('avatar_configs')->insert(rawConfigRow(['llm_model_id' => 'no-such-model']))))->toBeTrue();

    $unused = LlmModel::factory()->create();
    $unused->delete();
    expect(LlmModel::find($unused->id))->toBeNull();
});

it('refuses a config for an account that does not exist', function () {
    expect(rejected(fn () => DB::table('avatar_configs')->insert(rawConfigRow(['account_id' => (string) Str::ulid()]))))->toBeTrue();
});

it('is owned by one account: never mass-assignable, invisible to the others, fails closed without one', function () {
    $other = Account::factory()->create();
    $mine = inAccount($this->account, fn () => AvatarConfig::create(['name' => 'Mine', 'provider' => 'mock', 'account_id' => $other->id]));
    $theirs = makeConfig(['name' => 'Theirs'], $other);

    expect($mine->account_id)->toBe($this->account->id)
        ->and(inAccount($this->account, fn () => AvatarConfig::pluck('id')->all()))->toBe([$mine->id])
        ->and(inAccount($this->account, fn () => AvatarConfig::find($theirs->id)))->toBeNull()
        ->and(fn () => AvatarConfig::count())->toThrow(LogicException::class);
});

it('is found by route binding with its prefixed id, within the account only', function () {
    Route::middleware(['api', 'auth.secret'])->get('/v1/_configs/{avatarConfig}', fn (AvatarConfig $avatarConfig) => ['id' => $avatarConfig->id]);
    [, $raw] = issueKey($this->account);
    $own = makeConfig();
    $foreign = makeConfig([], Account::factory()->create());
    $headers = ['Authorization' => "Bearer {$raw}"];

    $this->getJson("/v1/_configs/{$own->id}", $headers)->assertOk()->assertExactJson(['id' => $own->id]);
    $this->getJson("/v1/_configs/{$foreign->id}", $headers)->assertStatus(404);
    $this->getJson('/v1/_configs/'.substr($own->id, 4), $headers)->assertStatus(404);
    $this->getJson('/v1/_configs/avc_not-a-ulid', $headers)->assertStatus(404);
});

it('refuses to store a list or a scalar where an object belongs', function (string $column, mixed $value) {
    expect(fn () => makeConfig([$column => $value]))->toThrow(InvalidArgumentException::class, $column);
})->with([
    'a list config' => ['config', [1, 2]],
    'a scalar persona' => ['persona', 'x'],
    'a list voice' => ['voice', ['a', 'b']],
    'a number provider state' => ['provider_state', 7],
]);

it('clears a persona and a voice by setting them to null', function () {
    $config = makeConfig(['persona' => ['name' => 'Ada'], 'voice' => ['engine' => 'cartesia', 'voice_id' => 'v1']]);

    inAccount($this->account, fn () => $config->update(['persona' => null, 'voice' => null]));

    expect($config->fresh()->persona)->toBeNull()->and($config->fresh()->voice)->toBeNull()
        ->and(DB::table('avatar_configs')->where('id', $config->id)->value('persona'))->toBeNull();
});

it('holds the column defaults before it is read back, so an adapter never meets a null state', function () {
    $config = new AvatarConfig(['name' => 'Fresh', 'provider' => 'mock']);

    expect($config->config)->toBe([])->and($config->provider_state)->toBe([]);
});
