<?php

use App\AvatarProviders\FailureClass;
use App\AvatarProviders\LiveAvatar\LiveAvatarApi;
use App\AvatarProviders\LiveAvatar\LiveAvatarLedger;
use App\AvatarProviders\LiveAvatar\LiveAvatarVoiceRegistrar;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ResourceKind;
use App\Credentials\CredentialResolver;
use App\Credentials\ProviderAuth;
use App\Models\Account;
use App\Models\ProviderCredential;
use App\Models\ProviderResource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const LA_VOICE = '00e9ec78-2002-41dd-8d19-6b1d3b17a461';

beforeEach(function () {
    $api = new LiveAvatarApi;
    $this->ledger = new LiveAvatarLedger($api);
    $this->registrar = new LiveAvatarVoiceRegistrar($api, $this->ledger, new CredentialResolver, lockWaitSeconds: 0);
    $this->account = Account::factory()->create();
    $this->auth = new ProviderAuth('la-key-one-123456', ProviderAuth::PLATFORM);
    laVoiceKey($this->account, 'cartesia', 'CARTESIA_KEY_X');
    laVoiceKey($this->account, 'elevenlabs', 'ELEVEN_KEY_X');
});

function laVoiceKey(Account $account, string $provider, string $key): void
{
    inAccount($account, fn () => ProviderCredential::query()->updateOrCreate(['provider' => $provider], ['api_key' => $key]));
}

function laVoiceFp(string $key): string
{
    return substr(hash('sha256', $key), 0, 16);
}

/** @param  array<string, mixed>  $voiceBody */
function laVoiceFake(string $secretId = 'sec-1', string $voiceId = 'bound-1', array $voiceBody = []): void
{
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => $secretId, 'secret_name' => 'x'], 'message' => 'ok']),
        'api.liveavatar.com/v1/voices/third_party' => Http::response(['code' => 1000, 'data' => ['voice_id' => $voiceId, ...$voiceBody], 'message' => 'Voice imported successfully']),
        'api.liveavatar.com/v1/voices/*' => Http::response(['code' => 1000, 'data' => null, 'message' => 'Voice deleted successfully']),
    ]);
}

function laVoiceCount(string $method, string $urlPart): int
{
    return Http::recorded()->filter(fn (array $pair) => $pair[0]->method() === $method && str_contains($pair[0]->url(), $urlPart))->count();
}

function laVoiceRows(Account $account, ResourceKind $kind): int
{
    return inAccount($account, fn () => ProviderResource::query()->where('kind', $kind->value)->count());
}

it('binds a Cartesia voice through a CARTESIA_API_KEY secret holding the account\'s own vendor key', function () {
    laVoiceFake('sec-1', 'bound-1', ['language' => 'en']);

    $voiceId = $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);

    // LiveAvatar reports every imported voice as English; the bound language is never read or returned.
    expect($voiceId)->toBe('bound-1');
    Http::assertSentInOrder([
        fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.liveavatar.com/v1/secrets'
            && $request->header('X-API-KEY') === ['la-key-one-123456']
            && $request->data() === [
                'secret_type' => 'CARTESIA_API_KEY',
                'secret_value' => 'CARTESIA_KEY_X',
                'secret_name' => 'hra-cartesia-'.laVoiceFp('CARTESIA_KEY_X'),
            ],
        fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.liveavatar.com/v1/voices/third_party'
            && $request->data() === ['provider_voice_id' => LA_VOICE, 'secret_id' => 'sec-1', 'name' => 'hra-cartesia-'.LA_VOICE],
    ]);
});

it('records the vendor secret and the bound voice in the ledger under the vendor key\'s fingerprint', function () {
    laVoiceFake('sec-1', 'bound-1');
    $fingerprint = laVoiceFp('CARTESIA_KEY_X');

    $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);

    $rows = inAccount($this->account, fn () => ProviderResource::query()->orderBy('kind')->get());
    expect($rows->map(fn (ProviderResource $row) => [$row->kind, $row->natural_key, $row->external_id, $row->key_fingerprint])->all())->toBe([
        [ResourceKind::VendorSecret, "cartesia:{$fingerprint}", 'sec-1', $this->auth->fingerprint],
        [ResourceKind::Voice, "cartesia:{$fingerprint}:".LA_VOICE, 'bound-1', $this->auth->fingerprint],
    ]);
});

it('uses an ELEVENLABS_API_KEY secret for an ElevenLabs voice', function () {
    laVoiceFake();

    $this->registrar->ensureVoice($this->account->id, $this->auth, 'elevenlabs', 'EL_voice_1');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/secrets')
        && $request['secret_type'] === 'ELEVENLABS_API_KEY' && $request['secret_value'] === 'ELEVEN_KEY_X');
});

it('shortens the voice name to the 64 characters LiveAvatar allows', function () {
    laVoiceFake();

    $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', str_repeat('v', 120));

    $name = Http::recorded()->last()[0]['name'];
    expect(mb_strlen($name))->toBe(64)->and($name)->toStartWith('hra-cartesia-vvv');
});

it('binds a voice once: the ledger answers every later start without a call', function () {
    laVoiceFake();

    $first = $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);
    $second = $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);

    expect($second)->toBe($first)
        ->and(laVoiceCount('POST', '/voices/third_party'))->toBe(1)
        ->and(laVoiceCount('POST', '/v1/secrets'))->toBe(1);
});

it('shares one secret between two voices of the same vendor key', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec-1']]),
        'api.liveavatar.com/v1/voices/third_party' => Http::sequence()->push(['data' => ['voice_id' => 'voice-1']])->push(['data' => ['voice_id' => 'voice-2']]),
    ]);

    $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);
    $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', 'second-voice');

    expect(laVoiceCount('POST', '/v1/secrets'))->toBe(1)
        ->and(laVoiceCount('POST', '/voices/third_party'))->toBe(2)
        ->and(laVoiceRows($this->account, ResourceKind::VendorSecret))->toBe(1);
});

it('treats the same vendor voice id under two engines as two voices', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::sequence()->push(['data' => ['id' => 'sec-c']])->push(['data' => ['id' => 'sec-e']]),
        'api.liveavatar.com/v1/voices/third_party' => Http::sequence()->push(['data' => ['voice_id' => 'voice-c']])->push(['data' => ['voice_id' => 'voice-e']]),
    ]);

    $cartesia = $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', 'shared-id');
    $eleven = $this->registrar->ensureVoice($this->account->id, $this->auth, 'elevenlabs', 'shared-id');

    expect([$cartesia, $eleven])->toBe(['voice-c', 'voice-e'])
        ->and(laVoiceRows($this->account, ResourceKind::Voice))->toBe(2);
});

it('uses each account\'s own vendor key and never a voice another account bound', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::sequence()->push(['data' => ['id' => 'sec-a']])->push(['data' => ['id' => 'sec-b']]),
        'api.liveavatar.com/v1/voices/third_party' => Http::sequence()->push(['data' => ['voice_id' => 'voice-a']])->push(['data' => ['voice_id' => 'voice-b']]),
    ]);
    $other = Account::factory()->create();
    laVoiceKey($other, 'cartesia', 'CARTESIA_KEY_OTHER');

    $mine = $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);
    $theirs = $this->registrar->ensureVoice($other->id, $this->auth, 'cartesia', LA_VOICE);

    expect([$mine, $theirs])->toBe(['voice-a', 'voice-b'])
        ->and(array_map(fn (Request $request) => $request['secret_value'], Http::recorded()->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/v1/secrets'))->map(fn (array $pair) => $pair[0])->values()->all()))
        ->toBe(['CARTESIA_KEY_X', 'CARTESIA_KEY_OTHER'])
        ->and(laVoiceRows($this->account, ResourceKind::Voice))->toBe(1)
        ->and(laVoiceRows($other, ResourceKind::Voice))->toBe(1);
});

it('falls back to the platform vendor key for an account with none of its own', function () {
    laVoiceFake();
    config()->set('hr-avatar.platform_keys.cartesia', 'CARTESIA_PLATFORM');
    $bare = Account::factory()->create();

    $this->registrar->ensureVoice($bare->id, $this->auth, 'cartesia', LA_VOICE);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/secrets') && $request['secret_value'] === 'CARTESIA_PLATFORM');
});

it('binds the voice again, with a new secret, when the account changes its vendor key', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::sequence()->push(['data' => ['id' => 'sec-old']])->push(['data' => ['id' => 'sec-new']]),
        'api.liveavatar.com/v1/voices/third_party' => Http::sequence()->push(['data' => ['voice_id' => 'voice-old']])->push(['data' => ['voice_id' => 'voice-new']]),
    ]);
    $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);
    laVoiceKey($this->account, 'cartesia', 'CARTESIA_KEY_ROTATED');

    $voiceId = $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);

    // The old voice still points at a secret holding the old key, so it must not be reused.
    expect($voiceId)->toBe('voice-new')
        ->and(laVoiceRows($this->account, ResourceKind::Voice))->toBe(2);
});

it('binds again when the account switches its LiveAvatar key', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::sequence()->push(['data' => ['id' => 'sec-1']])->push(['data' => ['id' => 'sec-2']]),
        'api.liveavatar.com/v1/voices/third_party' => Http::sequence()->push(['data' => ['voice_id' => 'voice-1']])->push(['data' => ['voice_id' => 'voice-2']]),
    ]);
    $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);

    $voiceId = $this->registrar->ensureVoice($this->account->id, new ProviderAuth('la-key-two-654321', ProviderAuth::PLATFORM), 'cartesia', LA_VOICE);

    expect($voiceId)->toBe('voice-2');
});

it('fails before any LiveAvatar call when the voice cannot be bound at all', function (string $engine, bool $withVendorKey) {
    Http::fake();
    $bare = Account::factory()->create();
    if ($withVendorKey) {
        laVoiceKey($bare, 'cartesia', 'CARTESIA_KEY_X');
    }

    expect(fn () => $this->registrar->ensureVoice($bare->id, $this->auth, $engine, LA_VOICE))->toThrow(ProviderException::class);

    Http::assertNothingSent();
})->with([
    'no vendor key' => ['cartesia', false],
    'an engine it cannot bind' => ['azure', true],
    'the provider\'s own voice is not an external engine' => ['provider', true],
]);

it('stores nothing when the secret cannot be created', function () {
    Http::fake(['api.liveavatar.com/v1/secrets' => Http::response(['message' => 'down'], 500)]);

    expect(fn () => $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE))->toThrow(ProviderException::class);

    expect(laVoiceRows($this->account, ResourceKind::VendorSecret))->toBe(0)
        ->and(laVoiceRows($this->account, ResourceKind::Voice))->toBe(0);
});

it('leaves no voice row when the bind fails, but keeps the secret for the retry', function (Closure $bind, FailureClass $class) {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec-1']]),
        'api.liveavatar.com/v1/voices/third_party' => $bind,
    ]);

    try {
        $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);
        $this->fail('expected a ProviderException');
    } catch (ProviderException $error) {
        expect($error->failureClass())->toBe($class)->and($error->getPrevious())->toBeNull();
    }

    expect(laVoiceRows($this->account, ResourceKind::Voice))->toBe(0)
        ->and(laVoiceRows($this->account, ResourceKind::VendorSecret))->toBe(1);
})->with([
    'a 500' => [fn () => Http::response(['code' => 5000, 'data' => null, 'message' => 'boom'], 500), FailureClass::Upstream],
    'a 429' => [fn () => Http::response([], 429), FailureClass::Throttle],
    'a 200 without a voice id' => [fn () => Http::response(['code' => 1000, 'data' => []]), FailureClass::Upstream],
    'a timeout' => [fn () => throw new ConnectionException('timeout'), FailureClass::Upstream],
]);

it('releases its lock when a bind fails, so the next attempt can bind', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec-1']]),
        'api.liveavatar.com/v1/voices/third_party' => Http::sequence()->push([], 500)->push(['data' => ['voice_id' => 'voice-1']]),
    ]);

    expect(fn () => $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE))->toThrow(ProviderException::class)
        ->and($this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE))->toBe('voice-1');
});

it('replaces a secret deleted in LiveAvatar once and retries the bind once', function () {
    $this->ledger->record($this->account->id, $this->auth, ResourceKind::VendorSecret, 'cartesia:'.laVoiceFp('CARTESIA_KEY_X'), 'gone-secret');
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'fresh-secret']]),
        'api.liveavatar.com/v1/voices/third_party' => Http::sequence()
            ->push(['code' => 4000, 'data' => null, 'message' => "Secret with id 'gone-secret' not found in your space"], 400)
            ->push(['code' => 1000, 'data' => ['voice_id' => 'bound-2']]),
    ]);

    $voiceId = $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);

    $binds = Http::recorded()->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/voices/third_party'))->map(fn (array $pair) => $pair[0]['secret_id'])->values()->all();
    expect($voiceId)->toBe('bound-2')
        ->and($binds)->toBe(['gone-secret', 'fresh-secret'])
        ->and(laVoiceCount('POST', '/v1/secrets'))->toBe(1);
});

it('gives up after that one retry rather than looping on a secret that keeps vanishing', function () {
    $this->ledger->record($this->account->id, $this->auth, ResourceKind::VendorSecret, 'cartesia:'.laVoiceFp('CARTESIA_KEY_X'), 'gone-secret');
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'fresh-secret']]),
        'api.liveavatar.com/v1/voices/third_party' => Http::response(['code' => 4000, 'data' => null, 'message' => 'Secret with id x not found in your space'], 400),
    ]);

    expect(fn () => $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE))->toThrow(ProviderException::class)
        ->and(laVoiceCount('POST', '/voices/third_party'))->toBe(2);
});

it('does not mistake another 400 for a missing secret', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec-1']]),
        'api.liveavatar.com/v1/voices/third_party' => Http::response(['code' => 4001, 'data' => null, 'message' => 'Invalid voice'], 400),
    ]);

    expect(fn () => $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE))->toThrow(ProviderException::class)
        ->and(laVoiceCount('POST', '/voices/third_party'))->toBe(1)
        ->and(laVoiceCount('POST', '/v1/secrets'))->toBe(1);
});

it('makes no second bind call for a start that arrives while the first is binding', function () {
    $inner = null;
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec-1']]),
        'api.liveavatar.com/v1/voices/third_party' => function () use (&$inner) {
            try {
                $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);
            } catch (ProviderException $error) {
                $inner = $error;
            }

            return Http::response(['data' => ['voice_id' => 'bound-1']]);
        },
    ]);

    $outer = $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);

    expect($outer)->toBe('bound-1')
        ->and($inner)->toBeInstanceOf(ProviderException::class)
        ->and(laVoiceCount('POST', '/voices/third_party'))->toBe(1)
        ->and($this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE))->toBe('bound-1');
});

it('hands a waiting start the voice the first one finished binding, without a call of its own', function () {
    Http::fake();
    $voiceKey = 'cartesia:'.laVoiceFp('CARTESIA_KEY_X').':'.LA_VOICE;
    $held = Cache::lock("hra:liveavatar:bind:{$this->account->id}:{$this->auth->fingerprint}:".sha1($voiceKey), 60);
    expect($held->get())->toBeTrue();
    Sleep::fake();
    // While this start waits, the one holding the lock finishes and records its voice.
    Sleep::whenFakingSleep(fn () => $this->ledger->record($this->account->id, $this->auth, ResourceKind::Voice, $voiceKey, 'bound-1'));
    $waiting = new LiveAvatarVoiceRegistrar(new LiveAvatarApi, $this->ledger, new CredentialResolver, lockWaitSeconds: 1);

    $voiceId = $waiting->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);

    expect($voiceId)->toBe('bound-1');
    Http::assertNothingSent();
});

it('adopts the winner and deletes its own duplicate when another process recorded the voice first', function () {
    $key = 'cartesia:'.laVoiceFp('CARTESIA_KEY_X').':'.LA_VOICE;
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['data' => ['id' => 'sec-1']]),
        'api.liveavatar.com/v1/voices/third_party' => function () use ($key) {
            // Another process (no shared lock) commits its row between our lookup and our insert.
            $this->ledger->record($this->account->id, $this->auth, ResourceKind::Voice, $key, 'winner-voice');

            return Http::response(['data' => ['voice_id' => 'loser-voice']]);
        },
        'api.liveavatar.com/v1/voices/loser-voice' => Http::response([]),
    ]);

    $voiceId = $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);

    expect($voiceId)->toBe('winner-voice')
        ->and(laVoiceRows($this->account, ResourceKind::Voice))->toBe(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://api.liveavatar.com/v1/voices/loser-voice');
});

it('lets no key into an exception or a log, even when LiveAvatar echoes them back', function () {
    Http::fake(['*' => Http::response(['message' => 'echo CARTESIA_KEY_X la-key-one-123456'], 500)]);
    $logs = captureLogs();

    try {
        $this->registrar->ensureVoice($this->account->id, $this->auth, 'cartesia', LA_VOICE);
    } catch (ProviderException $error) {
        $seen = $error->getMessage().$error->getTraceAsString();
    }

    expect($seen ?? null)->not->toBeNull()
        ->and($seen.implode("\n", $logs->getArrayCopy()))->not->toContain('CARTESIA_KEY_X')->not->toContain('la-key-one-123456');
});
