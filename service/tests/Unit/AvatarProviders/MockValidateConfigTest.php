<?php

use App\AvatarProviders\Mock\MockProvider;
use App\AvatarProviders\VoiceBinding;

it('accepts an empty config', function () {
    expect((new MockProvider)->validateConfig([], null))->toBe([]);
});

it('rejects any key, since it has no settings', function () {
    $errors = (new MockProvider)->validateConfig(['avatarId' => 'x'], null);

    expect(array_map(fn ($error) => "{$error->field}:{$error->code}", $errors))->toBe(['config.avatarId:unknown']);
});

it('takes the provider voice and nothing external', function (string $engine, array $expected) {
    $errors = (new MockProvider)->validateConfig([], new VoiceBinding($engine, 'v'));

    expect(array_map(fn ($error) => "{$error->field}:{$error->code}", $errors))->toBe($expected);
})->with([
    'provider' => ['provider', []],
    'cartesia' => ['cartesia', ['voice.engine:unsupported']],
    'elevenlabs' => ['elevenlabs', ['voice.engine:unsupported']],
]);

it('takes no persona knob', function () {
    $errors = (new MockProvider)->validateConfig([], null, ['x' => 1]);

    expect(array_map(fn ($error) => "{$error->field}:{$error->code}", $errors))->toBe(['persona.x:unknown']);
});

it('describes itself with no fields, resources or catalogue', function () {
    $description = (new MockProvider)->describe();

    expect($description->toArray())->toBe(['fields' => [], 'persona_fields' => [], 'resources' => [], 'voice_engines' => ['provider']]);
});
