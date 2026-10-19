<?php

use App\AvatarProviders\LiveAvatar\LiveAvatarProvider;
use App\AvatarProviders\Mock\MockProvider;
use App\AvatarProviders\Tavus\TavusProvider;
use App\Models\AvatarConfig;

/**
 * @param  array<string, mixed>  $attributes
 */
function syncSubject(string $provider, array $attributes = []): AvatarConfig
{
    return new AvatarConfig(['name' => 'Subject', 'provider' => $provider, 'config' => [], ...$attributes]);
}

it('is always required for Tavus: its persona must carry the config', function (array $attributes) {
    expect((new TavusProvider)->syncRequired(syncSubject('tavus', $attributes)))->toBeTrue();
})->with([
    'bare' => [[]],
    'with a model' => [['llm_model_id' => 'gem-1']],
    'with a voice' => [['voice' => ['engine' => 'cartesia', 'voice_id' => 'v']]],
]);

it('is never required for Mock, whatever it is bound to', function (array $attributes) {
    expect((new MockProvider)->syncRequired(syncSubject('mock', $attributes)))->toBeFalse();
})->with([
    'bare' => [[]],
    'with a model' => [['llm_model_id' => 'gem-1']],
]);

describe('LiveAvatar', function () {
    it('is not required for a plain config or a stock voice', function (array $attributes) {
        expect(app(LiveAvatarProvider::class)->syncRequired(syncSubject('heygen', $attributes)))->toBeFalse();
    })->with([
        'bare' => [[]],
        'stock voice' => [['voice' => ['engine' => 'provider', 'voice_id' => 'stock']]],
    ]);

    it('is required for a bound language model', function () {
        expect(app(LiveAvatarProvider::class)->syncRequired(syncSubject('heygen', ['llm_model_id' => 'gem-1'])))->toBeTrue();
    });

    it('is required for an external voice', function (string $engine) {
        expect(app(LiveAvatarProvider::class)->syncRequired(syncSubject('heygen', ['voice' => ['engine' => $engine, 'voice_id' => 'v']])))->toBeTrue();
    })->with(['cartesia', 'elevenlabs']);

    it('is still required when an LLM configuration is stored but no model is bound, so the stale one gets removed', function () {
        $config = syncSubject('heygen', ['provider_state' => ['llm_configuration_id' => 'llm_1']]);

        expect(app(LiveAvatarProvider::class)->syncRequired($config))->toBeTrue();
    });
});
