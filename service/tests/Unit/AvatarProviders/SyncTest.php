<?php

use App\AvatarProviders\LiveAvatar\LiveAvatarProvider;
use App\AvatarProviders\Mock\MockProvider;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ResourceKind;
use App\AvatarProviders\SyncBlocked;
use App\AvatarProviders\SyncResult;
use App\AvatarProviders\SyncStatus;
use App\AvatarProviders\Tavus\TavusProvider;
use App\Credentials\ProviderAuth;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\ProviderCredential;
use App\Models\ProviderResource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->auth = new ProviderAuth('la-key-123456', ProviderAuth::PLATFORM);
    LlmModel::factory()->create(['id' => 'gem-1', 'provider' => 'gemini', 'model' => 'gemini-x', 'base_url' => 'https://llm.example.test/v1/']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function syncConfig(string $provider, array $attributes = []): AvatarConfig
{
    return inAccount(test()->account, fn () => AvatarConfig::factory()->create(['provider' => $provider, ...$attributes]));
}

function syncStoreKeys(): void
{
    inAccount(test()->account, function () {
        LlmCredential::create(['llm_provider' => 'gemini', 'api_key' => 'gm-key-123456']);
        ProviderCredential::create(['provider' => 'cartesia', 'api_key' => 'CARTESIA_KEY_X']);
    });
}

function syncLiveAvatarFake(): void
{
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::sequence()
            ->push(['code' => 1000, 'data' => ['id' => 'sec-llm']])
            ->push(['code' => 1000, 'data' => ['id' => 'sec-voice']]),
        'api.liveavatar.com/v1/llm-configurations' => Http::response(['code' => 1000, 'data' => ['id' => 'llm-cfg-1']]),
        'api.liveavatar.com/v1/llm-configurations/*' => Http::response(['code' => 1000, 'data' => null]),
        'api.liveavatar.com/v1/voices/third_party' => Http::response(['code' => 1000, 'data' => ['voice_id' => 'bound-1']]),
    ]);
}

describe('SyncResult', function () {
    it('says whether a sync produced objects or none were needed', function () {
        expect(SyncResult::synced()->status)->toBe(SyncStatus::Synced)
            ->and(SyncResult::notRequired()->status)->toBe(SyncStatus::NotRequired);
    });
});

describe('Mock', function () {
    it('has nothing to sync and says so without any call', function () {
        Http::fake();

        $result = (new MockProvider)->sync(syncConfig('mock'), $this->auth);

        expect($result)->toEqual(SyncResult::notRequired());
        Http::assertNothingSent();
    });
});

describe('Tavus', function () {
    it('delegates to the persona sync', function () {
        Http::fake(['*' => Http::response([], 200)]);
        $config = syncConfig('tavus', ['config' => ['replica_id' => 'r', 'persona_id' => 'p_9'], 'persona' => ['llm_temperature' => 1]]);

        $result = (new TavusProvider)->sync($config, $this->auth);

        expect($result)->toEqual(SyncResult::synced());
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request->url() === 'https://tavusapi.com/v2/pals/p_9');
    });

    it('lets a provider failure through with its generic message', function () {
        Http::fake(['*' => Http::response(['detail' => 'tv-key-123456'], 500)]);
        $config = syncConfig('tavus', ['config' => ['replica_id' => 'r', 'persona_id' => 'p_9'], 'persona' => ['llm_temperature' => 1]]);

        expect(fn () => (new TavusProvider)->sync($config, $this->auth))->toThrow(ProviderException::class, 'Provider request failed (status 500)');
    });
});

describe('LiveAvatar', function () {
    it('registers the language-model configuration and the external voice and stores the references', function () {
        syncLiveAvatarFake();
        syncStoreKeys();
        $config = syncConfig('heygen', ['config' => ['avatarId' => 'a'], 'llm_model_id' => 'gem-1', 'voice' => ['engine' => 'cartesia', 'voice_id' => 'v-1']]);

        $result = app(LiveAvatarProvider::class)->sync($config, $this->auth);

        expect($result)->toEqual(SyncResult::synced())
            ->and($config->refresh()->provider_state)->toBe(['llm_configuration_id' => 'llm-cfg-1'])
            ->and(inAccount($this->account, fn () => ProviderResource::query()->where('kind', ResourceKind::Voice->value)->value('external_id')))->toBe('bound-1');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://api.liveavatar.com/v1/llm-configurations');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://api.liveavatar.com/v1/voices/third_party');
    });

    it('is idempotent: a second sync updates the configuration and binds nothing again', function () {
        syncLiveAvatarFake();
        syncStoreKeys();
        $config = syncConfig('heygen', ['config' => ['avatarId' => 'a'], 'llm_model_id' => 'gem-1', 'voice' => ['engine' => 'cartesia', 'voice_id' => 'v-1']]);
        $adapter = app(LiveAvatarProvider::class);

        $adapter->sync($config, $this->auth);
        $adapter->sync($config->refresh(), $this->auth);

        $posts = fn (string $suffix) => Http::recorded()->filter(fn (array $pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), $suffix))->count();
        expect($posts('/llm-configurations'))->toBe(1)
            ->and($posts('/voices/third_party'))->toBe(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request->url() === 'https://api.liveavatar.com/v1/llm-configurations/llm-cfg-1');
    });

    it('removes the configuration of a model that is no longer bound', function () {
        syncLiveAvatarFake();
        $config = syncConfig('heygen', ['config' => ['avatarId' => 'a'], 'provider_state' => ['llm_configuration_id' => 'llm-cfg-1']]);

        $result = app(LiveAvatarProvider::class)->sync($config, $this->auth);

        expect($result)->toEqual(SyncResult::synced())->and($config->refresh()->provider_state)->toBe([]);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://api.liveavatar.com/v1/llm-configurations/llm-cfg-1');
    });

    it('has nothing to register for a plain config or the stock voice', function () {
        Http::fake();
        $config = syncConfig('heygen', ['config' => ['avatarId' => 'a'], 'voice' => ['engine' => 'provider', 'voice_id' => 'stock']]);

        expect(app(LiveAvatarProvider::class)->sync($config, $this->auth))->toEqual(SyncResult::synced());
        Http::assertNothingSent();
    });

    it('blocks the sync when the bound model has no key, before anything is sent', function () {
        Http::fake();
        $config = syncConfig('heygen', ['config' => ['avatarId' => 'a'], 'llm_model_id' => 'gem-1']);

        expect(fn () => app(LiveAvatarProvider::class)->sync($config, $this->auth))->toThrow(SyncBlocked::class, 'The language model credential is missing.');
        Http::assertNothingSent();
    });

    it('blocks the sync when the external voice has no vendor key, before anything is sent', function () {
        Http::fake();
        $config = syncConfig('heygen', ['config' => ['avatarId' => 'a'], 'voice' => ['engine' => 'cartesia', 'voice_id' => 'v-1']]);

        expect(fn () => app(LiveAvatarProvider::class)->sync($config, $this->auth))->toThrow(SyncBlocked::class, 'The voice vendor credential is missing.');
        Http::assertNothingSent();
    });

    it('lets a provider failure through with its generic message and no body', function () {
        Http::fake(['*' => Http::response(['detail' => 'la-key-123456 rejected'], 500)]);
        syncStoreKeys();
        $config = syncConfig('heygen', ['config' => ['avatarId' => 'a'], 'llm_model_id' => 'gem-1']);

        try {
            app(LiveAvatarProvider::class)->sync($config, $this->auth);
            $this->fail('A refused registration must raise.');
        } catch (ProviderException $failure) {
            expect($failure->getMessage())->toBe('Provider request failed (status 500)')->and($failure->getPrevious())->toBeNull();
        }
    });
});

describe('SyncBlocked', function () {
    it('carries one fixed sentence per precondition and no previous exception', function (string $factory, string $message) {
        $blocked = SyncBlocked::$factory();

        expect($blocked->getMessage())->toBe($message)->and($blocked->getPrevious())->toBeNull();
    })->with([
        ['providerCredentialMissing', 'The provider credential is missing.'],
        ['llmCredentialMissing', 'The language model credential is missing.'],
        ['voiceCredentialMissing', 'The voice vendor credential is missing.'],
        ['incompleteConfig', 'The avatar configuration is incomplete.'],
    ]);
});
