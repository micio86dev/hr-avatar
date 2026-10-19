<?php

use App\AvatarProviders\ProviderException;
use App\AvatarProviders\SyncBlocked;
use App\AvatarProviders\SyncResult;
use App\AvatarProviders\Tavus\TavusPersonaSync;
use App\Credentials\ProviderAuth;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->auth = new ProviderAuth('tv-key-123456', ProviderAuth::PLATFORM);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function personaConfig(array $attributes = []): AvatarConfig
{
    return inAccount(test()->account, fn () => AvatarConfig::factory()->create([
        'provider' => 'tavus',
        'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_123'],
        ...$attributes,
    ]));
}

function personaSync(AvatarConfig $config): SyncResult
{
    return app(TavusPersonaSync::class)->sync($config, test()->auth);
}

function personaLlmKey(string $key = 'gm-key-123456'): void
{
    inAccount(test()->account, fn () => LlmCredential::create(['llm_provider' => 'gemini', 'api_key' => $key]));
}

/**
 * @return array<string, mixed>
 */
function personaSentLayers(): array
{
    $sent = Http::recorded()->first();

    return $sent[0]->data()[0]['value'];
}

describe('what is pushed', function () {
    it('replaces the whole layers node of the persona in one operation, authenticated with the account key', function () {
        Http::fake(['*' => Http::response([], 200)]);

        $result = personaSync(personaConfig(['persona' => ['llm_temperature' => 0.5, 'turn_taking_patience' => 'high']]));

        expect($result)->toEqual(SyncResult::synced());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && $request->url() === 'https://tavusapi.com/v2/pals/p_123'
            && $request->header('x-api-key') === ['tv-key-123456']
            && $request->data() === [['op' => 'add', 'path' => '/layers', 'value' => [
                'llm' => ['extra_body' => ['temperature' => 0.5]],
                'conversational_flow' => ['turn_taking_patience' => 'high'],
            ]]]);
    });

    it('maps every persona knob onto its layer path', function () {
        Http::fake(['*' => Http::response([], 200)]);

        personaSync(personaConfig(['persona' => [
            'llm_temperature' => 0.5, 'llm_speculative_inference' => true, 'turn_taking_patience' => 'high',
            'interruptibility' => 'low', 'voice_isolation' => 'near', 'idle_engagement' => 'eager',
        ]]));

        expect(personaSentLayers())->toEqual([
            'llm' => ['extra_body' => ['temperature' => 0.5], 'speculative_inference' => true],
            'conversational_flow' => ['turn_taking_patience' => 'high', 'pal_interruptibility' => 'low', 'voice_isolation' => 'near', 'idle_engagement' => 'eager'],
        ]);
    });

    it('sends the external voice as engine and voice id, with the model the config names', function () {
        Http::fake(['*' => Http::response([], 200)]);

        personaSync(personaConfig([
            'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_123', 'tts_model_name' => 'sonic-3.5'],
            'voice' => ['engine' => 'cartesia', 'voice_id' => 'v-1'],
        ]));

        expect(personaSentLayers())->toEqual(['tts' => ['tts_engine' => 'cartesia', 'external_voice_id' => 'v-1', 'tts_model_name' => 'sonic-3.5']]);
    });

    it('always sends a model for an external voice, because the update would otherwise reset it to the provider default', function (string $engine, string $model) {
        Http::fake(['*' => Http::response([], 200)]);

        personaSync(personaConfig(['voice' => ['engine' => $engine, 'voice_id' => 'v-1']]));

        expect(personaSentLayers()['tts']['tts_model_name'])->toBe($model);
    })->with([
        'cartesia' => ['cartesia', 'sonic-3'],
        'elevenlabs' => ['elevenlabs', 'eleven_multilingual_v2'],
    ]);

    it('sends nothing for the stock voice', function () {
        Http::fake();

        $result = personaSync(personaConfig(['voice' => ['engine' => 'provider', 'voice_id' => 'stock']]));

        expect($result)->toEqual(SyncResult::synced());
        Http::assertNothingSent();
    });

    it('sends nothing when there is nothing to say: an empty layers node would wipe the persona', function () {
        Http::fake();

        $result = personaSync(personaConfig());

        expect($result)->toEqual(SyncResult::synced());
        Http::assertNothingSent();
    });

    it('is idempotent: syncing twice pushes the same body', function () {
        Http::fake(['*' => Http::response([], 200)]);
        $config = personaConfig(['persona' => ['llm_temperature' => 0.5]]);

        personaSync($config);
        personaSync($config);

        $bodies = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->all();
        expect($bodies)->toHaveCount(2)->and($bodies[0])->toBe($bodies[1]);
    });

    it('treats a 304 as synced: the persona already holds these values', function () {
        Http::fake(['*' => Http::response([], 304)]);

        expect(personaSync(personaConfig(['persona' => ['llm_temperature' => 0.5]])))->toEqual(SyncResult::synced());
    });
});

describe('the language-model binding', function () {
    beforeEach(function () {
        LlmModel::factory()->create(['id' => 'gem-1', 'provider' => 'gemini', 'model' => 'gemini-x', 'base_url' => 'https://llm.example.test/v1/']);
    });

    it('is merged into the llm node next to the temperature, not over it', function () {
        Http::fake(['*' => Http::response([], 200)]);
        personaLlmKey();

        personaSync(personaConfig(['llm_model_id' => 'gem-1', 'persona' => ['llm_temperature' => 0.3]]));

        expect(personaSentLayers()['llm'])->toEqual([
            'extra_body' => ['temperature' => 0.3],
            'model' => 'gemini-x',
            'base_url' => 'https://llm.example.test/v1/',
            'api_key' => 'gm-key-123456',
        ]);
    });

    it('is enough on its own: a persona with no other knob still gets the model', function () {
        Http::fake(['*' => Http::response([], 200)]);
        personaLlmKey();

        personaSync(personaConfig(['llm_model_id' => 'gem-1']));

        expect(array_keys(personaSentLayers()))->toBe(['llm']);
    });

    it('is sent again on every sync: the provider does not keep the key', function () {
        Http::fake(['*' => Http::response([], 200)]);
        personaLlmKey();
        $config = personaConfig(['llm_model_id' => 'gem-1']);

        personaSync($config);
        personaSync($config);

        expect(Http::recorded()->every(fn (array $pair) => $pair[0]->data()[0]['value']['llm']['api_key'] === 'gm-key-123456'))->toBeTrue();
    });

    it('blocks the sync when a model is bound and the account has no key for it', function () {
        Http::fake();
        $config = personaConfig(['llm_model_id' => 'gem-1', 'persona' => ['llm_temperature' => 0.3]]);

        expect(fn () => personaSync($config))->toThrow(SyncBlocked::class, 'The language model credential is missing.');
        Http::assertNothingSent();
    });
});

describe('failures', function () {
    it('raises a provider exception with the status and nothing the provider wrote', function (int $status) {
        Http::fake(['*' => Http::response(['message' => 'Invalid persona_id tv-key-123456 echoed'], $status)]);

        try {
            personaSync(personaConfig(['persona' => ['llm_temperature' => 0.5]]));
            $this->fail('A refused update must raise.');
        } catch (ProviderException $failure) {
            expect($failure->getMessage())->toBe("Provider request failed (status {$status})")
                ->and($failure->getPrevious())->toBeNull();
        }
    })->with([400, 401, 403, 404, 422, 429, 500, 503]);

    it('raises a provider exception when the provider cannot be reached', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out after sending tv-key-123456'));

        expect(fn () => personaSync(personaConfig(['persona' => ['llm_temperature' => 0.5]])))
            ->toThrow(ProviderException::class, 'Provider request failed (no response)');
    });

    it('blocks a config without a persona id instead of guessing one', function (array $config) {
        Http::fake();

        expect(fn () => personaSync(personaConfig(['config' => $config, 'persona' => ['llm_temperature' => 0.5]])))
            ->toThrow(SyncBlocked::class, 'The avatar configuration is incomplete.');
        Http::assertNothingSent();
    })->with([
        'absent' => [['replica_id' => 'r']],
        'empty' => [['replica_id' => 'r', 'persona_id' => '']],
        'not text' => [['replica_id' => 'r', 'persona_id' => 5]],
    ]);

    it('does not put the persona id in a url unescaped', function () {
        Http::fake(['*' => Http::response([], 200)]);

        personaSync(personaConfig(['config' => ['replica_id' => 'r', 'persona_id' => 'p/../x?y'], 'persona' => ['llm_temperature' => 0.5]]));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://tavusapi.com/v2/pals/p%2F..%2Fx%3Fy');
    });
});
