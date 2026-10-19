<?php

use App\AvatarProviders\FailureClass;
use App\AvatarProviders\LiveAvatar\LiveAvatarApi;
use App\AvatarProviders\LiveAvatar\LiveAvatarLedger;
use App\AvatarProviders\LiveAvatar\LiveAvatarLlmRegistrar;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ResourceKind;
use App\AvatarProviders\SyncStatus;
use App\Credentials\ProviderAuth;
use App\Llm\LlmBinding;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\ProviderResource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $api = new LiveAvatarApi;
    $this->registrar = new LiveAvatarLlmRegistrar($api, new LiveAvatarLedger($api));
    $this->account = Account::factory()->create();
    $this->auth = new ProviderAuth('la-key-one-123456', ProviderAuth::PLATFORM);
    $this->binding = new LlmBinding('gemini-3.1-flash-lite-preview', 'https://llm.example.test/v1/', 'gem-key-AAAA-1111');
});

function laLlmConfig(Account $account, array $state = []): AvatarConfig
{
    return inAccount($account, fn () => AvatarConfig::factory()->create([
        'provider' => 'heygen', 'sync_status' => SyncStatus::Pending, 'provider_state' => $state,
    ]));
}

function laLlmFake(string $secretId = 'sec_1', string $configId = 'cfg_1'): void
{
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => $secretId, 'secret_name' => 'x'], 'message' => 'success']),
        'api.liveavatar.com/v1/llm-configurations' => Http::response(['data' => ['id' => $configId, 'secret_id' => $secretId]]),
        'api.liveavatar.com/v1/llm-configurations/*' => Http::response(['data' => ['id' => $configId, 'secret_id' => $secretId]]),
    ]);
}

/** @return list<Request> */
function laLlmSent(string $method, string $urlPart): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => $pair[0]->method() === $method && str_contains($pair[0]->url(), $urlPart))
        ->map(fn (array $pair) => $pair[0])
        ->values()
        ->all();
}

function laLlmState(AvatarConfig $config): array
{
    return inAccount(Account::query()->findOrFail($config->account_id), fn () => AvatarConfig::query()->findOrFail($config->id)->provider_state);
}

function laLlmRows(Account $account): int
{
    return inAccount($account, fn () => ProviderResource::query()->where('kind', ResourceKind::LlmSecret->value)->count());
}

it('registers the account\'s key as a secret and then a configuration, with exactly the documented bodies', function () {
    laLlmFake('sec_new', 'cfg_new');
    $config = laLlmConfig($this->account);

    $id = $this->registrar->ensureConfiguration($config, $this->binding, $this->auth);

    $nameFingerprint = substr(hash('sha256', 'gem-key-AAAA-1111'), 0, 16);
    expect($id)->toBe('cfg_new');
    Http::assertSentInOrder([
        fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.liveavatar.com/v1/secrets'
            && $request->header('X-API-KEY') === ['la-key-one-123456']
            && $request->data() === ['secret_type' => 'OPENAI_API_KEY', 'secret_value' => 'gem-key-AAAA-1111', 'secret_name' => "hra-llm-{$nameFingerprint}"],
        fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.liveavatar.com/v1/llm-configurations'
            && $request->header('X-API-KEY') === ['la-key-one-123456']
            && $request->data() === [
                'display_name' => "hra-{$config->id}",
                'model_name' => 'gemini-3.1-flash-lite-preview',
                'base_url' => 'https://llm.example.test/v1',
                'secret_id' => 'sec_new',
            ],
    ]);
});

it('stores the configuration id in the config\'s provider state and the secret in the ledger', function () {
    laLlmFake('sec_new', 'cfg_new');
    $config = laLlmConfig($this->account, ['other' => 'kept']);

    $this->registrar->ensureConfiguration($config, $this->binding, $this->auth);

    $secret = inAccount($this->account, fn () => ProviderResource::query()->sole());
    expect(laLlmState($config))->toBe(['other' => 'kept', 'llm_configuration_id' => 'cfg_new'])
        ->and($secret->external_id)->toBe('sec_new')
        ->and($secret->kind)->toBe(ResourceKind::LlmSecret)
        ->and($secret->key_fingerprint)->toBe($this->auth->fingerprint)
        ->and($secret->natural_key)->toBe(substr(hash('sha256', 'gem-key-AAAA-1111'), 0, 16));
});

it('sends the model base URL without a trailing slash, because LiveAvatar joins its own', function (string $stored) {
    laLlmFake();

    $this->registrar->ensureConfiguration(laLlmConfig($this->account), new LlmBinding('m', $stored, 'k-1234567890'), $this->auth);

    expect(laLlmSent('POST', '/llm-configurations')[0]['base_url'])->toBe('https://llm.example.test/v1');
})->with(['with the slash' => ['https://llm.example.test/v1/'], 'without it' => ['https://llm.example.test/v1']]);

it('updates the stored configuration on a re-run instead of creating another, and creates no second secret', function () {
    laLlmFake();
    $config = laLlmConfig($this->account);
    $this->registrar->ensureConfiguration($config, $this->binding, $this->auth);

    $again = $this->registrar->ensureConfiguration($config, $this->binding, $this->auth);

    expect($again)->toBe('cfg_1')
        ->and(laLlmSent('POST', '/secrets'))->toHaveCount(1)
        ->and(laLlmSent('POST', '/llm-configurations'))->toHaveCount(1)
        ->and(laLlmSent('PATCH', '/llm-configurations/cfg_1'))->toHaveCount(1)
        ->and(laLlmSent('PATCH', '/llm-configurations/cfg_1')[0]->data())->toBe([
            'display_name' => "hra-{$config->id}",
            'model_name' => 'gemini-3.1-flash-lite-preview',
            'base_url' => 'https://llm.example.test/v1',
            'secret_id' => 'sec_1',
        ])
        ->and(laLlmRows($this->account))->toBe(1);
});

it('keeps the stored id when an update answers without one', function () {
    Http::fake([
        'api.liveavatar.com/v1/llm-configurations/cfg_old' => Http::response(['data' => []]),
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec_1']]),
    ]);
    $config = laLlmConfig($this->account, ['llm_configuration_id' => 'cfg_old']);

    expect($this->registrar->ensureConfiguration($config, $this->binding, $this->auth))->toBe('cfg_old');
});

it('re-points the configuration at a new secret when the account changes its model key', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::sequence()->push(['data' => ['id' => 'sec_1']])->push(['data' => ['id' => 'sec_rotated']]),
        'api.liveavatar.com/v1/llm-configurations' => Http::response(['data' => ['id' => 'cfg_1']]),
        'api.liveavatar.com/v1/llm-configurations/cfg_1' => Http::response(['data' => ['id' => 'cfg_1']]),
    ]);
    $config = laLlmConfig($this->account);
    $this->registrar->ensureConfiguration($config, $this->binding, $this->auth);

    $this->registrar->ensureConfiguration($config, new LlmBinding('gemini-3.1-flash-lite-preview', 'https://llm.example.test/v1/', 'gem-key-BBBB-2222'), $this->auth);

    expect(array_map(fn (Request $request) => $request['secret_value'], laLlmSent('POST', '/secrets')))->toBe(['gem-key-AAAA-1111', 'gem-key-BBBB-2222'])
        ->and(laLlmSent('PATCH', '/llm-configurations/cfg_1')[0]['secret_id'])->toBe('sec_rotated')
        ->and(laLlmRows($this->account))->toBe(2);
});

it('shares one secret between the configs of an account that use the same key', function () {
    laLlmFake();

    $this->registrar->ensureConfiguration(laLlmConfig($this->account), $this->binding, $this->auth);
    $this->registrar->ensureConfiguration(laLlmConfig($this->account), $this->binding, $this->auth);

    expect(laLlmSent('POST', '/secrets'))->toHaveCount(1)
        ->and(laLlmRows($this->account))->toBe(1);
});

it('never lets one account reuse the secret another account created, even for an identical key', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::sequence()->push(['data' => ['id' => 'sec_a']])->push(['data' => ['id' => 'sec_b']]),
        'api.liveavatar.com/v1/llm-configurations' => Http::response(['data' => ['id' => 'cfg_x']]),
    ]);
    $other = Account::factory()->create();

    $this->registrar->ensureConfiguration(laLlmConfig($this->account), $this->binding, $this->auth);
    $this->registrar->ensureConfiguration(laLlmConfig($other), $this->binding, $this->auth);

    expect(array_map(fn (Request $request) => $request['secret_id'], laLlmSent('POST', '/llm-configurations')))->toBe(['sec_a', 'sec_b'])
        ->and(laLlmRows($this->account))->toBe(1)
        ->and(laLlmRows($other))->toBe(1);
});

it('creates new secret and configuration objects when the account switches its LiveAvatar key', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::sequence()->push(['data' => ['id' => 'sec_one']])->push(['data' => ['id' => 'sec_two']]),
        // The configuration belongs to the old key's space, which the new key cannot see.
        'api.liveavatar.com/v1/llm-configurations/cfg_one' => Http::response(['message' => 'not found'], 404),
        'api.liveavatar.com/v1/llm-configurations' => Http::sequence()->push(['data' => ['id' => 'cfg_one']])->push(['data' => ['id' => 'cfg_two']]),
    ]);
    $config = laLlmConfig($this->account);
    $this->registrar->ensureConfiguration($config, $this->binding, $this->auth);

    $id = $this->registrar->ensureConfiguration($config, $this->binding, new ProviderAuth('la-key-two-654321', ProviderAuth::PLATFORM));

    expect($id)->toBe('cfg_two')
        ->and(laLlmState($config))->toBe(['llm_configuration_id' => 'cfg_two'])
        ->and(laLlmRows($this->account))->toBe(2)
        ->and(laLlmSent('POST', '/secrets')[1]->header('X-API-KEY'))->toBe(['la-key-two-654321']);
});

it('recreates a configuration deleted in LiveAvatar exactly once: update, 404, create', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec_1']]),
        'api.liveavatar.com/v1/llm-configurations/cfg_gone' => Http::response([], 404),
        'api.liveavatar.com/v1/llm-configurations' => Http::response(['data' => ['id' => 'cfg_new']]),
    ]);
    $config = laLlmConfig($this->account, ['llm_configuration_id' => 'cfg_gone']);

    $id = $this->registrar->ensureConfiguration($config, $this->binding, $this->auth);

    $verbs = Http::recorded()->map(fn (array $pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
    expect($id)->toBe('cfg_new')
        ->and($verbs)->toBe(['POST /v1/secrets', 'PATCH /v1/llm-configurations/cfg_gone', 'POST /v1/llm-configurations'])
        ->and(laLlmState($config))->toBe(['llm_configuration_id' => 'cfg_new']);
});

it('does not loop when the recreate also fails: one error, and the dead id is forgotten', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec_1']]),
        'api.liveavatar.com/v1/llm-configurations/cfg_gone' => Http::response([], 404),
        'api.liveavatar.com/v1/llm-configurations' => Http::response([], 404),
    ]);
    $config = laLlmConfig($this->account, ['llm_configuration_id' => 'cfg_gone']);

    expect(fn () => $this->registrar->ensureConfiguration($config, $this->binding, $this->auth))->toThrow(ProviderException::class)
        ->and(laLlmState($config))->toBe([])
        ->and(Http::recorded())->toHaveCount(3);
});

it('raises a provider error, and keeps no half-state, for each way the provider can fail', function (Closure $fake, FailureClass $class, int $secretsKept) {
    $fake();
    $config = laLlmConfig($this->account);

    try {
        $this->registrar->ensureConfiguration($config, $this->binding, $this->auth);
        $this->fail('expected a ProviderException');
    } catch (ProviderException $error) {
        expect($error->failureClass())->toBe($class)
            ->and($error->getPrevious())->toBeNull();
    }

    // A secret that was created stays in the ledger for the retry; the config never holds a configuration id.
    expect(laLlmState($config))->toBe([])
        ->and(laLlmRows($this->account))->toBe($secretsKept);
})->with([
    'the secret call fails' => [fn () => Http::fake(['api.liveavatar.com/v1/secrets' => Http::response(['message' => 'boom'], 500)]), FailureClass::Upstream, 0],
    'the secret call is throttled' => [fn () => Http::fake(['api.liveavatar.com/v1/secrets' => Http::response([], 429)]), FailureClass::Throttle, 0],
    'the secret call has no id' => [fn () => Http::fake(['api.liveavatar.com/v1/secrets' => Http::response(['data' => ['secret_name' => 'x']])]), FailureClass::Upstream, 0],
    'the configuration is refused' => [fn () => laLlmFakeConfig(422), FailureClass::ClientError, 1],
    'the configuration call fails' => [fn () => laLlmFakeConfig(503), FailureClass::Upstream, 1],
    'the configuration answer has no id' => [fn () => laLlmFakeConfig(200, ['data' => ['display_name' => 'x']]), FailureClass::Upstream, 1],
    'the configuration call times out' => [fn () => Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec_1']]),
        'api.liveavatar.com/v1/llm-configurations' => fn () => throw new ConnectionException('timeout'),
    ]), FailureClass::Upstream, 1],
]);

function laLlmFakeConfig(int $status, array $body = ['message' => 'x']): void
{
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec_1']]),
        'api.liveavatar.com/v1/llm-configurations' => Http::response($body, $status),
    ]);
}

it('forgets a configuration: deletes it and clears the id', function () {
    Http::fake(['api.liveavatar.com/v1/llm-configurations/cfg_1' => Http::response([])]);
    $config = laLlmConfig($this->account, ['llm_configuration_id' => 'cfg_1', 'other' => 'kept']);

    $this->registrar->forget($config, $this->auth);

    expect(laLlmSent('DELETE', '/llm-configurations/cfg_1'))->toHaveCount(1)
        ->and(laLlmState($config))->toBe(['other' => 'kept']);
});

it('forgets a config that never had one without calling LiveAvatar', function () {
    Http::fake();
    $config = laLlmConfig($this->account);

    $this->registrar->forget($config, $this->auth);

    Http::assertNothingSent();
});

it('clears the id and never throws when the delete fails, logging the status class only', function (Closure $fake, string $logged) {
    $fake();
    $logs = captureLogs();
    $config = laLlmConfig($this->account, ['llm_configuration_id' => 'cfg_1']);

    $this->registrar->forget($config, $this->auth);

    expect(laLlmState($config))->toBe([])
        ->and(implode("\n", $logs->getArrayCopy()))->toContain($logged)->not->toContain('secret body');
})->with([
    'a 500' => [fn () => Http::fake(['*' => Http::response(['message' => 'secret body'], 500)]), '5xx'],
    'a transport failure' => [fn () => Http::fake(fn () => throw new ConnectionException('secret body')), 'none'],
]);

it('treats an already deleted configuration as success, without a warning', function () {
    Http::fake(['*' => Http::response([], 404)]);
    $logs = captureLogs();
    $config = laLlmConfig($this->account, ['llm_configuration_id' => 'cfg_1']);

    $this->registrar->forget($config, $this->auth);

    expect(laLlmState($config))->toBe([])->and($logs->getArrayCopy())->toBe([]);
});

it('lets neither key into an exception or a log, even when LiveAvatar echoes them back', function () {
    Http::fake(['*' => Http::response(['message' => 'Unauthorized: gem-key-AAAA-1111 la-key-one-123456'], 401)]);
    $logs = captureLogs();

    try {
        $this->registrar->ensureConfiguration(laLlmConfig($this->account), $this->binding, $this->auth);
    } catch (ProviderException $error) {
        $seen = $error->getMessage().$error->getTraceAsString();
    }
    $this->registrar->forget(laLlmConfig($this->account, ['llm_configuration_id' => 'cfg_1']), $this->auth);

    $everything = ($seen ?? '').implode("\n", $logs->getArrayCopy());
    expect($seen ?? null)->not->toBeNull()
        ->and($everything)->not->toContain('gem-key-AAAA-1111')->not->toContain('la-key-one-123456');
});
