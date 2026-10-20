<?php

use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\FailureClass;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\LiveAvatar\LiveAvatarProvider;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ProviderRegistry;
use App\AvatarProviders\SyncStatus;
use App\Credentials\ProviderAuth;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\ProviderCredential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00', 'UTC'));
    $this->provider = app(LiveAvatarProvider::class);
    $this->account = Account::factory()->create();
    $this->auth = new ProviderAuth('la-secret-key-123456', ProviderAuth::PLATFORM);
});

/** @param  array<string, mixed>  $attributes */
function laStartConfig(Account $account, array $attributes = []): AvatarConfig
{
    return inAccount($account, fn () => AvatarConfig::factory()->create([
        'provider' => 'heygen', 'sync_status' => SyncStatus::Synced, 'config' => ['avatarId' => 'av-1'], ...$attributes,
    ]));
}

function laStartSpec(?string $opening = 'Ciao!', string $language = 'it'): ConversationSpec
{
    return new ConversationSpec('Be kind.', $opening, $language, 600, new stdClass);
}

function laStartFake(): void
{
    Http::fake([
        'api.liveavatar.com/v1/contexts' => Http::response(['code' => 1000, 'data' => ['id' => 'ctx_1']]),
        'api.liveavatar.com/v1/sessions/token' => Http::response(['data' => ['session_id' => 'sess_1', 'session_token' => 'tok_1']]),
        'api.liveavatar.com/v1/contexts/*' => Http::response(['code' => 1000]),
    ]);
}

/** @return list<Request> */
function laStartSent(string $method, string $urlPart): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => $pair[0]->method() === $method && str_contains($pair[0]->url(), $urlPart))
        ->map(fn (array $pair) => $pair[0])
        ->values()
        ->all();
}

function laStart(?AvatarConfig $config = null, ?ConversationSpec $spec = null, ?string $callbackUrl = null): mixed
{
    return test()->provider->start('conv_abc', $spec ?? laStartSpec(), $config ?? laStartConfig(test()->account), test()->auth, $callbackUrl);
}

it('creates the context first and then requests a token that references it', function () {
    laStartFake();
    $config = laStartConfig($this->account, ['config' => ['avatarId' => 'av-1', 'videoQuality' => 'high']]);

    laStart($config);

    Http::assertSentInOrder([
        fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.liveavatar.com/v1/contexts'
            && $request->header('X-API-KEY') === ['la-secret-key-123456']
            && preg_match('/^hra-conv_abc-[0-9A-Za-z]{26}$/', $request['name']) === 1
            && array_diff_key($request->data(), ['name' => 1]) === ['prompt' => 'Be kind.', 'opening_text' => 'Ciao!'],
        fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.liveavatar.com/v1/sessions/token'
            && $request->header('X-API-KEY') === ['la-secret-key-123456']
            && $request->data() === [
                'avatar_id' => 'av-1',
                'interactivity_type' => 'CONVERSATIONAL',
                'video_settings' => ['quality' => 'high'],
                'mode' => 'FULL',
                'is_sandbox' => false,
                'avatar_persona' => ['language' => 'it', 'context_id' => 'ctx_1'],
            ],
    ]);
});

it('returns the refs to tear the session down and the join material for the browser, without the key', function () {
    laStartFake();

    $session = laStart();

    expect(json_encode($session->joinMaterial->toArray()))->not->toContain('la-secret-key-123456');

    expect($session->sessionRef)->toBe('sess_1')
        ->and($session->contextRef)->toBe('ctx_1')
        ->and($session->joinMaterial->toArray())->toBe([
            'provider' => 'heygen',
            'transport' => 'liveavatar',
            'conversation_url' => null,
            'session_token' => 'tok_1',
            'expires_at' => '2026-10-19T13:00:00Z',
        ]);
});

it('names every context uniquely, even for a second start of the same conversation', function () {
    laStartFake();

    laStart();
    laStart();

    $names = array_map(fn (Request $request) => $request['name'], laStartSent('POST', '/contexts'));
    expect($names)->toHaveCount(2)->and($names[0])->not->toBe($names[1]);
});

it('sends no opening text when the conversation has none', function () {
    laStartFake();

    laStart(spec: laStartSpec(opening: null));

    expect(laStartSent('POST', '/contexts')[0]->data())->not->toHaveKey('opening_text');
});

it('ignores the callback URL: LiveAvatar has no webhooks', function () {
    laStartFake();

    laStart(callbackUrl: 'https://hra.example.test/webhooks/providers/heygen/abc');

    foreach (Http::recorded() as [$request]) {
        expect(json_encode($request->data()))->not->toContain('callback')->not->toContain('hra.example.test');
    }
});

it('sends the language of the conversation, whatever language the bound voice reports', function () {
    laStartFake();

    laStart(spec: laStartSpec(language: 'en'));

    expect(laStartSent('POST', '/sessions/token')[0]['avatar_persona']['language'])->toBe('en');
});

it('forwards allowlisted config fields only', function () {
    laStartFake();
    $config = laStartConfig($this->account, ['config' => ['avatarId' => 'av-9', 'interactivityType' => 'PUSH_TO_TALK', 'maxSessionDurationSec' => 600, 'foo' => 'bar']]);

    laStart($config);

    $body = laStartSent('POST', '/sessions/token')[0]->data();
    expect($body['avatar_id'])->toBe('av-9')
        ->and($body['interactivity_type'])->toBe('PUSH_TO_TALK')
        ->and(json_encode($body))->not->toContain('maxSession')->not->toContain('foo');
});

it('sends the language-model configuration from the provider state at the top level only', function () {
    laStartFake();
    $config = laStartConfig($this->account, ['provider_state' => ['llm_configuration_id' => 'cfg_9']]);

    laStart($config);

    $token = laStartSent('POST', '/sessions/token')[0];
    expect($token['llm_configuration_id'])->toBe('cfg_9')
        ->and($token['avatar_persona'])->not->toHaveKey('llm_configuration_id')
        ->and(laStartSent('POST', '/contexts')[0]->data())->not->toHaveKey('llm_configuration_id');
});

it('sends none when the config has no language-model configuration', function () {
    laStartFake();

    laStart();

    expect(laStartSent('POST', '/sessions/token')[0]->data())->not->toHaveKey('llm_configuration_id');
});

describe('voice', function () {
    it('sends the provider\'s own voice id, and binds nothing', function () {
        laStartFake();
        $config = laStartConfig($this->account, ['voice' => ['engine' => 'provider', 'voice_id' => 'stock-voice']]);

        laStart($config);

        expect(laStartSent('POST', '/sessions/token')[0]['avatar_persona']['voice_id'])->toBe('stock-voice')
            ->and(laStartSent('POST', '/secrets'))->toBe([]);
    });

    it('binds an external voice before anything billable and sends the bound id with its settings', function () {
        Http::fake([
            'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec_v']]),
            'api.liveavatar.com/v1/voices/third_party' => Http::response(['data' => ['voice_id' => 'bound-voice', 'language' => 'en']]),
            'api.liveavatar.com/v1/contexts' => Http::response(['data' => ['id' => 'ctx_1']]),
            'api.liveavatar.com/v1/sessions/token' => Http::response(['data' => ['session_id' => 'sess_1', 'session_token' => 'tok_1']]),
        ]);
        inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'cartesia', 'api_key' => 'CARTESIA_KEY_X']));
        $config = laStartConfig($this->account, ['voice' => ['engine' => 'cartesia', 'voice_id' => 'v-1'], 'config' => ['avatarId' => 'av-1', 'voiceSpeed' => 1.1]]);

        laStart($config, laStartSpec(language: 'it'));

        $paths = Http::recorded()->map(fn (array $pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
        $token = laStartSent('POST', '/sessions/token')[0];
        expect($paths)->toBe(['/v1/secrets', '/v1/voices/third_party', '/v1/contexts', '/v1/sessions/token'])
            ->and($token['avatar_persona'])->toBe(['voice_id' => 'bound-voice', 'language' => 'it', 'context_id' => 'ctx_1'])
            ->and($token['voice_settings'])->toBe(['provider' => 'cartesia', 'speed' => 1.1, 'model' => 'sonic-3.5']);
    });

    it('fails before any LiveAvatar call when the voice cannot be bound for lack of a vendor key', function () {
        Http::fake();
        $config = laStartConfig($this->account, ['voice' => ['engine' => 'elevenlabs', 'voice_id' => 'v-1']]);

        expect(fn () => laStart($config))->toThrow(ProviderException::class);

        Http::assertNothingSent();
    });

    it('creates no context and no session when the bind itself fails', function () {
        Http::fake([
            'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec_v']]),
            'api.liveavatar.com/v1/voices/third_party' => Http::response(['message' => 'boom'], 500),
        ]);
        inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'cartesia', 'api_key' => 'CARTESIA_KEY_X']));
        $config = laStartConfig($this->account, ['voice' => ['engine' => 'cartesia', 'voice_id' => 'v-1']]);

        expect(fn () => laStart($config))->toThrow(ProviderException::class);

        expect(laStartSent('POST', '/contexts'))->toBe([])->and(laStartSent('POST', '/sessions/token'))->toBe([]);
    });
});

describe('failures', function () {
    it('creates no session and deletes nothing when the context cannot be created', function (Closure $answer, FailureClass $class) {
        Http::fake(['api.liveavatar.com/v1/contexts' => $answer]);

        try {
            laStart();
            $this->fail('expected a ProviderException');
        } catch (ProviderException $error) {
            expect($error->failureClass())->toBe($class);
        }

        expect(laStartSent('POST', '/sessions/token'))->toBe([])->and(laStartSent('DELETE', '/contexts'))->toBe([]);
    })->with([
        '500' => [fn () => Http::response(['message' => 'boom'], 500), FailureClass::Upstream],
        '429' => [fn () => Http::response([], 429), FailureClass::Throttle],
        '422' => [fn () => Http::response(['message' => 'prompt is required'], 422), FailureClass::ClientError],
        'a timeout' => [fn () => throw new ConnectionException('timeout'), FailureClass::Upstream],
        'no id in the answer' => [fn () => Http::response(['data' => []]), FailureClass::Upstream],
    ]);

    it('deletes the context it just created, then raises the original failure, whatever stops the token call', function (Closure $answer, FailureClass $class) {
        Http::fake([
            'api.liveavatar.com/v1/contexts' => Http::response(['data' => ['id' => 'ctx_1']]),
            'api.liveavatar.com/v1/sessions/token' => $answer,
            'api.liveavatar.com/v1/contexts/ctx_1' => Http::response([]),
        ]);

        try {
            laStart();
            $this->fail('expected a ProviderException');
        } catch (ProviderException $error) {
            expect($error->failureClass())->toBe($class);
        }

        expect(laStartSent('DELETE', '/contexts/ctx_1'))->toHaveCount(1);
    })->with([
        '500' => [fn () => Http::response(['message' => 'boom'], 500), FailureClass::Upstream],
        '429' => [fn () => Http::response([], 429), FailureClass::Throttle],
        '422' => [fn () => Http::response(['message' => 'avatar_id: Field required'], 422), FailureClass::ClientError],
        'a timeout' => [fn () => throw new ConnectionException('timeout'), FailureClass::Upstream],
        'no session token' => [fn () => Http::response(['data' => ['session_id' => 'sess_1']]), FailureClass::Upstream],
        'no session id, which could never be torn down' => [fn () => Http::response(['data' => ['session_token' => 'tok_1']]), FailureClass::Upstream],
    ]);

    it('still raises the original failure when the cleanup delete fails as well', function (Closure $cleanup) {
        Http::fake([
            'api.liveavatar.com/v1/contexts' => Http::response(['data' => ['id' => 'ctx_1']]),
            'api.liveavatar.com/v1/sessions/token' => Http::response([], 429),
            'api.liveavatar.com/v1/contexts/ctx_1' => $cleanup,
        ]);

        try {
            laStart();
            $this->fail('expected a ProviderException');
        } catch (ProviderException $error) {
            expect($error->failureClass())->toBe(FailureClass::Throttle);
        }
    })->with([
        'a 500' => [fn () => Http::response([], 500)],
        'a timeout' => [fn () => throw new ConnectionException('timeout')],
    ]);

    it('treats an already deleted context as cleaned up, without a log line', function () {
        Http::fake([
            'api.liveavatar.com/v1/contexts' => Http::response(['data' => ['id' => 'ctx_1']]),
            'api.liveavatar.com/v1/sessions/token' => Http::response([], 500),
            'api.liveavatar.com/v1/contexts/ctx_1' => Http::response([], 404),
        ]);
        $logs = captureLogs();

        expect(fn () => laStart())->toThrow(ProviderException::class)
            ->and($logs->getArrayCopy())->toBe([]);
    });

    it('logs only the status class of a cleanup that failed', function () {
        Http::fake([
            'api.liveavatar.com/v1/contexts' => Http::response(['data' => ['id' => 'ctx_1']]),
            'api.liveavatar.com/v1/sessions/token' => Http::response([], 500),
            'api.liveavatar.com/v1/contexts/ctx_1' => Http::response(['message' => 'body of the provider'], 503),
        ]);
        $logs = captureLogs();

        expect(fn () => laStart())->toThrow(ProviderException::class)
            ->and(implode("\n", $logs->getArrayCopy()))->toContain('5xx')->not->toContain('body of the provider');
    });

    it('lets the provider key into no exception and no log, even when LiveAvatar echoes it back', function () {
        Http::fake(['*' => Http::response(['message' => 'Unauthorized: la-secret-key-123456'], 401)]);
        $logs = captureLogs();

        try {
            laStart();
        } catch (ProviderException $error) {
            $seen = $error->getMessage().$error->getTraceAsString();
        }

        expect($seen ?? null)->not->toBeNull()
            ->and($seen.implode("\n", $logs->getArrayCopy()))->not->toContain('la-secret-key-123456')->not->toContain('Unauthorized');
    });
});

it('ingests by polling, since LiveAvatar sends no webhooks', function () {
    expect($this->provider->ingestion())->toBe(Ingestion::Poll);
});

it('is registered as the heygen provider, polled, with the liveavatar event key prefix', function () {
    $registry = new ProviderRegistry;

    expect($registry->get('heygen'))->toBeInstanceOf(LiveAvatarProvider::class)
        ->and($registry->requiresCredential('heygen'))->toBeTrue()
        ->and($registry->eventKeyPrefix('heygen'))->toBe('liveavatar')
        ->and($registry->withIngestion(Ingestion::Poll))->toContain('heygen');
});
