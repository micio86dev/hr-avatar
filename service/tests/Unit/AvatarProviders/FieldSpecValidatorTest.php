<?php

use App\AvatarProviders\FieldError;
use App\AvatarProviders\FieldSpec;
use App\AvatarProviders\FieldSpecValidator;
use App\AvatarProviders\FieldType;
use App\AvatarProviders\ProviderDescription;
use App\AvatarProviders\VoiceBinding;

/**
 * @param  list<FieldError>  $errors
 * @return list<string>
 */
function fieldErrorPairs(array $errors): array
{
    return array_map(fn (FieldError $error) => "{$error->field}:{$error->code}", $errors);
}

/**
 * @return list<FieldSpec>
 */
function fieldSpecSample(): array
{
    return [
        new FieldSpec('avatar', FieldType::Text, required: true, catalogueResource: 'avatar'),
        new FieldSpec('timeout', FieldType::Integer, min: 0, max: 100),
        new FieldSpec('speed', FieldType::Number, min: 0.5, max: 2),
        new FieldSpec('quality', FieldType::Select, options: ['low', 'high']),
        new FieldSpec('boost', FieldType::Checkbox),
    ];
}

function voiceDependentSpecs(): array
{
    return [
        new FieldSpec('model', FieldType::Select, options: ['a1', 'a2', 'b1'], optionsByVoiceEngine: ['cartesia' => ['a1', 'a2'], 'elevenlabs' => ['b1']], voiceEngines: ['cartesia', 'elevenlabs']),
        new FieldSpec('stability', FieldType::Number, min: 0, max: 1, voiceEngines: ['elevenlabs']),
    ];
}

function describedProvider(): ProviderDescription
{
    return new ProviderDescription(
        fields: [new FieldSpec('avatar', FieldType::Text, required: true)],
        personaFields: [new FieldSpec('temperature', FieldType::Number, min: 0, max: 2)],
        resources: ['avatar'],
        voiceEngines: ['provider', 'cartesia'],
    );
}

describe('FieldSpecValidator', function () {
    it('accepts a complete valid config', function () {
        $data = ['avatar' => 'a_1', 'timeout' => 10, 'speed' => 1.5, 'quality' => 'high', 'boost' => true];

        expect(FieldSpecValidator::validate(fieldSpecSample(), $data, null, 'config'))->toBe([]);
    });

    it('accepts a config that holds only the required keys', function () {
        expect(FieldSpecValidator::validate(fieldSpecSample(), ['avatar' => 'a_1'], null, 'config'))->toBe([]);
    });

    it('names an unknown key under the given path', function () {
        $errors = FieldSpecValidator::validate(fieldSpecSample(), ['avatar' => 'a_1', 'avatarID' => 'typo'], null, 'config');

        expect(fieldErrorPairs($errors))->toBe(['config.avatarID:unknown']);
    });

    it('uses the path it is given', function () {
        $errors = FieldSpecValidator::validate(fieldSpecSample(), ['avatar' => 'a_1', 'x' => 1], null, 'persona');

        expect(fieldErrorPairs($errors))->toBe(['persona.x:unknown']);
    });

    it('reports a missing or null required key', function (array $data) {
        expect(fieldErrorPairs(FieldSpecValidator::validate(fieldSpecSample(), $data, null, 'config')))->toBe(['config.avatar:required']);
    })->with([
        'absent' => [[]],
        'null' => [['avatar' => null]],
    ]);

    it('treats a null optional key as absent', function () {
        expect(FieldSpecValidator::validate(fieldSpecSample(), ['avatar' => 'a', 'quality' => null, 'timeout' => null], null, 'config'))->toBe([]);
    });

    it('rejects a value of the wrong type', function (string $key, mixed $value) {
        $errors = FieldSpecValidator::validate(fieldSpecSample(), ['avatar' => 'a', $key => $value], null, 'config');

        expect(fieldErrorPairs($errors))->toBe(["config.{$key}:type"]);
    })->with([
        'text as number' => ['avatar', 7],
        'text as list' => ['avatar', ['a']],
        'integer as float' => ['timeout', 1.5],
        'integer as string' => ['timeout', '5'],
        'integer as bool' => ['timeout', true],
        'number as string' => ['speed', '1'],
        'number as bool' => ['speed', true],
        'select as number' => ['quality', 1],
        'checkbox as string' => ['boost', 'yes'],
        'checkbox as int' => ['boost', 1],
    ]);

    it('accepts an integer where a number is expected', function () {
        expect(FieldSpecValidator::validate(fieldSpecSample(), ['avatar' => 'a', 'speed' => 1], null, 'config'))->toBe([]);
    });

    it('reports a number outside its limits as a range error, bounds included', function (string $key, int|float $value, bool $valid) {
        $errors = FieldSpecValidator::validate(fieldSpecSample(), ['avatar' => 'a', $key => $value], null, 'config');

        expect(fieldErrorPairs($errors))->toBe($valid ? [] : ["config.{$key}:range"]);
    })->with([
        'integer below' => ['timeout', -1, false],
        'integer at minimum' => ['timeout', 0, true],
        'integer at maximum' => ['timeout', 100, true],
        'integer above' => ['timeout', 101, false],
        'number below' => ['speed', 0.49, false],
        'number at minimum' => ['speed', 0.5, true],
        'number above' => ['speed', 2.01, false],
    ]);

    it('reports a select value outside its options as enum', function () {
        $errors = FieldSpecValidator::validate(fieldSpecSample(), ['avatar' => 'a', 'quality' => 'ultra'], null, 'config');

        expect(fieldErrorPairs($errors))->toBe(['config.quality:enum']);
    });

    it('returns every problem at once, unknown keys first', function () {
        $errors = FieldSpecValidator::validate(fieldSpecSample(), ['stray' => 1, 'timeout' => 'x', 'quality' => 'ultra'], null, 'config');

        expect(fieldErrorPairs($errors))->toBe(['config.stray:unknown', 'config.avatar:required', 'config.timeout:type', 'config.quality:enum']);
    });
});

describe('voice-dependent fields', function () {
    it('accepts a model that belongs to the bound engine', function (string $engine, string $model) {
        $errors = FieldSpecValidator::validate(voiceDependentSpecs(), ['model' => $model], new VoiceBinding($engine, 'v'), 'config');

        expect($errors)->toBe([]);
    })->with([
        'cartesia' => ['cartesia', 'a2'],
        'elevenlabs' => ['elevenlabs', 'b1'],
    ]);

    it('rejects a model of another engine as enum', function () {
        $errors = FieldSpecValidator::validate(voiceDependentSpecs(), ['model' => 'b1'], new VoiceBinding('cartesia', 'v'), 'config');

        expect(fieldErrorPairs($errors))->toBe(['config.model:enum']);
    });

    it('rejects a field that only applies to an external voice when there is none', function (?VoiceBinding $voice) {
        $errors = FieldSpecValidator::validate(voiceDependentSpecs(), ['model' => 'a1'], $voice, 'config');

        expect(fieldErrorPairs($errors))->toBe(['config.model:requires_voice_engine']);
    })->with([
        'no voice' => [null],
        'provider voice' => [new VoiceBinding('provider', 'v')],
    ]);

    it('rejects a knob the bound engine does not take', function () {
        $errors = FieldSpecValidator::validate(voiceDependentSpecs(), ['stability' => 0.4], new VoiceBinding('cartesia', 'v'), 'config');

        expect(fieldErrorPairs($errors))->toBe(['config.stability:requires_voice_engine']);
    });

    it('accepts a knob the bound engine takes', function () {
        expect(FieldSpecValidator::validate(voiceDependentSpecs(), ['stability' => 0.4], new VoiceBinding('elevenlabs', 'v'), 'config'))->toBe([]);
    });

    it('checks the voice dependence before the value, so a dead knob is never reported as a bad value', function () {
        $errors = FieldSpecValidator::validate(voiceDependentSpecs(), ['stability' => 9], null, 'config');

        expect(fieldErrorPairs($errors))->toBe(['config.stability:requires_voice_engine']);
    });
});

describe('FieldSpecValidator::validateConfig', function () {
    it('validates config, persona and the voice engine together', function () {
        $errors = FieldSpecValidator::validateConfig(describedProvider(), ['avatar' => 'a'], new VoiceBinding('cartesia', 'v'), ['temperature' => 1]);

        expect($errors)->toBe([]);
    });

    it('refuses a voice engine the provider does not support', function () {
        $errors = FieldSpecValidator::validateConfig(describedProvider(), ['avatar' => 'a'], new VoiceBinding('elevenlabs', 'v'), []);

        expect(fieldErrorPairs($errors))->toBe(['voice.engine:unsupported']);
    });

    it('names persona problems under persona', function () {
        $errors = FieldSpecValidator::validateConfig(describedProvider(), ['avatar' => 'a'], null, ['temperature' => 5, 'other' => 1]);

        expect(fieldErrorPairs($errors))->toBe(['persona.other:unknown', 'persona.temperature:range']);
    });

    it('does not blame a voice-gated setting for a voice the provider already refused', function () {
        $description = new ProviderDescription(
            fields: [new FieldSpec('speed', FieldType::Number, voiceEngines: ['cartesia'])],
            personaFields: [],
            resources: [],
            voiceEngines: ['provider'],
        );

        $errors = FieldSpecValidator::validateConfig($description, ['speed' => 1], new VoiceBinding('cartesia', 'v'), []);

        expect(fieldErrorPairs($errors))->toBe(['voice.engine:unsupported']);
    });

    // Review rv15: a refused voice must not hide a wrong type, range or enum on a voice-gated setting (one round trip, not two).
    it('still checks the type, range and enum of a voice-gated setting when the voice was refused', function (array $config, array $expected) {
        $description = new ProviderDescription(
            fields: [
                new FieldSpec('speed', FieldType::Number, min: 0.5, max: 2, voiceEngines: ['cartesia']),
                new FieldSpec('flag', FieldType::Checkbox, voiceEngines: ['cartesia']),
                new FieldSpec('model', FieldType::Select, options: ['a1', 'b1'], optionsByVoiceEngine: ['cartesia' => ['a1']], voiceEngines: ['cartesia']),
            ],
            personaFields: [],
            resources: [],
            voiceEngines: ['provider'],
        );

        $errors = FieldSpecValidator::validateConfig($description, $config, new VoiceBinding('cartesia', 'v'), []);

        expect(fieldErrorPairs($errors))->toBe(['voice.engine:unsupported', ...$expected]);
    })->with([
        'a wrong type' => [['speed' => 'fast'], ['config.speed:type']],
        'a value out of range' => [['speed' => 9], ['config.speed:range']],
        'a wrong type on a checkbox' => [['flag' => 'yes'], ['config.flag:type']],
        'a select that is not text' => [['model' => 3], ['config.model:type']],
        'a valid value' => [['speed' => 1, 'flag' => true, 'model' => 'a1'], []],
        // Which options are allowed depends on the engine, and the engine was refused: there is nothing to judge a name by.
        'a select name no engine was asked about' => [['model' => 'zzz'], []],
    ]);

    it('still checks the rest of the config when the voice was refused', function () {
        $errors = FieldSpecValidator::validateConfig(describedProvider(), [], new VoiceBinding('elevenlabs', 'v'), []);

        expect(fieldErrorPairs($errors))->toBe(['voice.engine:unsupported', 'config.avatar:required']);
    });
});

describe('FieldError', function () {
    it('has a fixed English sentence per code', function (string $code) {
        $message = (new FieldError('config.x', $code))->message();

        expect($message)->toBeString()->not->toBe('')->not->toContain('config.x');
    })->with(['required', 'type', 'range', 'enum', 'unknown', 'requires_voice_engine', 'unsupported']);

    it('keeps the code and the field it was given', function () {
        $error = new FieldError('config.x', 'type');

        expect([$error->field, $error->code])->toBe(['config.x', 'type']);
    });
});

describe('FieldSpec::toArray', function () {
    it('lists only what the spec sets, so a client sees no empty members', function () {
        expect((new FieldSpec('avatar', FieldType::Text, required: true, catalogueResource: 'avatar'))->toArray())
            ->toBe(['key' => 'avatar', 'type' => 'text', 'required' => true, 'catalogue_resource' => 'avatar']);
    });

    it('reports limits, options and voice dependence', function () {
        $spec = new FieldSpec('model', FieldType::Select, options: ['a', 'b'], min: 1, max: 3, optionsByVoiceEngine: ['cartesia' => ['a']], voiceEngines: ['cartesia']);

        expect($spec->toArray())->toBe([
            'key' => 'model', 'type' => 'select', 'required' => false, 'options' => ['a', 'b'], 'min' => 1, 'max' => 3,
            'options_by_voice_engine' => ['cartesia' => ['a']], 'voice_engines' => ['cartesia'],
        ]);
    });
});

describe('VoiceBinding', function () {
    it('is built from a stored voice object', function () {
        $voice = VoiceBinding::fromArray(['engine' => 'cartesia', 'voice_id' => 'v-1']);

        expect([$voice?->engine, $voice?->voiceId, $voice?->isExternal()])->toBe(['cartesia', 'v-1', true]);
    });

    it('treats the provider engine as the stock voice', function () {
        expect(VoiceBinding::fromArray(['engine' => 'provider', 'voice_id' => 'v'])?->isExternal())->toBeFalse();
    });

    it('is null when there is no usable voice', function (?array $stored) {
        expect(VoiceBinding::fromArray($stored))->toBeNull();
    })->with([
        'null' => [null],
        'empty' => [[]],
        'engine only' => [['engine' => 'cartesia']],
        'wrong types' => [['engine' => 3, 'voice_id' => []]],
    ]);

    it('names the engines the voice binding accepts', function () {
        expect(VoiceBinding::ENGINES)->toBe(['provider', 'cartesia', 'elevenlabs']);
    });
});

describe('ProviderDescription::toArray', function () {
    it('is the shape the catalogue publishes', function () {
        expect(describedProvider()->toArray())->toBe([
            'fields' => [['key' => 'avatar', 'type' => 'text', 'required' => true]],
            'persona_fields' => [['key' => 'temperature', 'type' => 'number', 'required' => false, 'min' => 0, 'max' => 2]],
            'resources' => ['avatar'],
            'voice_engines' => ['provider', 'cartesia'],
        ]);
    });
});
