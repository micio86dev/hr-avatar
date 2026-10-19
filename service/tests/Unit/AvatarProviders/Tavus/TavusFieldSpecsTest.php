<?php

use App\AvatarProviders\FieldSpec;
use App\AvatarProviders\FieldSpecValidator;
use App\AvatarProviders\FieldType;
use App\AvatarProviders\Tavus\TavusFieldSpecs;
use App\AvatarProviders\Tavus\TavusProvider;
use App\AvatarProviders\VoiceBinding;

/**
 * @param  list<FieldSpec>  $specs
 * @return array<string, FieldSpec>
 */
function tavusSpecsByKey(array $specs): array
{
    return array_column(array_map(fn (FieldSpec $spec) => ['key' => $spec->key, 'spec' => $spec], $specs), 'spec', 'key');
}

/**
 * @param  array<string, mixed>  $config
 * @param  array<string, mixed>  $persona
 * @return list<string>
 */
function tavusErrors(array $config, ?VoiceBinding $voice = null, array $persona = []): array
{
    return array_map(
        fn ($error) => "{$error->field}:{$error->code}",
        (new TavusProvider)->validateConfig($config, $voice, $persona),
    );
}

describe('Tavus field specs', function () {
    it('declares the keys the payload reads', function () {
        $config = tavusSpecsByKey(TavusFieldSpecs::config());

        expect($config['replica_id']->type)->toBe(FieldType::Text)
            ->and($config['replica_id']->required)->toBeTrue()
            ->and($config['replica_id']->catalogueResource)->toBe('replica')
            ->and($config['persona_id']->type)->toBe(FieldType::Text)
            ->and($config['persona_id']->required)->toBeTrue()
            ->and($config['persona_id']->catalogueResource)->toBe('pal')
            ->and($config['participant_left_timeout']->type)->toBe(FieldType::Integer)
            ->and($config['participant_left_timeout']->required)->toBeFalse();
    });

    it('declares no key twice and no back-office label', function () {
        foreach ([TavusFieldSpecs::config(), TavusFieldSpecs::persona()] as $specs) {
            $keys = array_map(fn (FieldSpec $spec) => $spec->key, $specs);

            expect($keys)->toBe(array_values(array_unique($keys)));
        }

        expect(json_encode(TavusFieldSpecs::config()))->not->toContain('label')->not->toContain('hint');
    });

    it('keeps the language-model choice out of the persona: the bound model owns that path', function () {
        $keys = array_keys(tavusSpecsByKey(TavusFieldSpecs::persona()));

        expect($keys)->not->toContain('llm_model')->not->toContain('model');
    });

    it('offers the persona knobs that reach the persona layers', function () {
        expect(array_keys(tavusSpecsByKey(TavusFieldSpecs::persona())))->toBe([
            'llm_temperature', 'llm_speculative_inference', 'turn_taking_patience', 'interruptibility', 'voice_isolation', 'idle_engagement',
        ]);
    });

    it('names the models each external engine takes', function () {
        expect(TavusFieldSpecs::TTS_MODELS)->toBe([
            'cartesia' => ['sonic-3', 'sonic-3.5', 'sonic-3.6'],
            'elevenlabs' => ['eleven_multilingual_v2', 'eleven_turbo_v2_5', 'eleven_flash_v2_5'],
        ])->and(TavusFieldSpecs::DEFAULT_TTS_MODEL)->toBe(['cartesia' => 'sonic-3', 'elevenlabs' => 'eleven_multilingual_v2']);
    });
});

describe('Tavus validateConfig', function () {
    it('accepts the identifiers alone', function () {
        expect(tavusErrors(['replica_id' => 'r_1', 'persona_id' => 'p_1']))->toBe([]);
    });

    it('rejects a key outside the field specification', function () {
        expect(tavusErrors(['replica_id' => 'r', 'persona_id' => 'p', 'faceId' => 'x']))->toBe(['config.faceId:unknown']);
    });

    it('names a missing required identifier', function (array $config, array $expected) {
        expect(tavusErrors($config))->toBe($expected);
    })->with([
        'persona missing' => [['replica_id' => 'r'], ['config.persona_id:required']],
        'replica missing' => [['persona_id' => 'p'], ['config.replica_id:required']],
        'both missing' => [[], ['config.replica_id:required', 'config.persona_id:required']],
    ]);

    it('takes a whole-number left-timeout and nothing else', function (mixed $value, array $expected) {
        expect(tavusErrors(['replica_id' => 'r', 'persona_id' => 'p', 'participant_left_timeout' => $value]))->toBe($expected);
    })->with([
        'zero' => [0, []],
        'ceiling' => [3600, []],
        'negative' => [-1, ['config.participant_left_timeout:range']],
        'above the ceiling' => [3601, ['config.participant_left_timeout:range']],
        'fraction' => [10.5, ['config.participant_left_timeout:type']],
        'text' => ['10', ['config.participant_left_timeout:type']],
    ]);

    it('accepts an external voice and a model of its engine', function () {
        $errors = tavusErrors(['replica_id' => 'r', 'persona_id' => 'p', 'tts_model_name' => 'sonic-3'], new VoiceBinding('cartesia', 'v-1'));

        expect($errors)->toBe([]);
    });

    it('rejects a model of another engine', function () {
        $errors = tavusErrors(['replica_id' => 'r', 'persona_id' => 'p', 'tts_model_name' => 'eleven_flash_v2_5'], new VoiceBinding('cartesia', 'v-1'));

        expect($errors)->toBe(['config.tts_model_name:enum']);
    });

    it('rejects a model when the voice is the stock one, which takes none', function () {
        $errors = tavusErrors(['replica_id' => 'r', 'persona_id' => 'p', 'tts_model_name' => 'sonic-3'], new VoiceBinding('provider', 'stock'));

        expect($errors)->toBe(['config.tts_model_name:requires_voice_engine']);
    });

    it('accepts all three voice engines', function (string $engine) {
        expect(tavusErrors(['replica_id' => 'r', 'persona_id' => 'p'], new VoiceBinding($engine, 'v')))->toBe([]);
    })->with(['provider', 'cartesia', 'elevenlabs']);

    it('rejects an unknown engine', function () {
        expect(tavusErrors(['replica_id' => 'r', 'persona_id' => 'p'], new VoiceBinding('azure', 'v')))->toBe(['voice.engine:unsupported']);
    });

    it('validates the persona knobs', function (array $persona, array $expected) {
        expect(tavusErrors(['replica_id' => 'r', 'persona_id' => 'p'], null, $persona))->toBe($expected);
    })->with([
        'valid' => [['llm_temperature' => 0.5, 'llm_speculative_inference' => true, 'turn_taking_patience' => 'high', 'interruptibility' => 'low', 'voice_isolation' => 'near', 'idle_engagement' => 'eager'], []],
        'temperature too high' => [['llm_temperature' => 2.5], ['persona.llm_temperature:range']],
        'bad enum' => [['turn_taking_patience' => 'extreme'], ['persona.turn_taking_patience:enum']],
        'unknown knob' => [['ttsEngine' => 'cartesia'], ['persona.ttsEngine:unknown']],
        'bad type' => [['llm_speculative_inference' => 'yes'], ['persona.llm_speculative_inference:type']],
    ]);

    it('can be described without a provider call', function () {
        $description = (new TavusProvider)->describe();

        expect($description->resources)->toBe(['replica', 'pal'])
            ->and($description->voiceEngines)->toBe(['provider', 'cartesia', 'elevenlabs'])
            ->and($description->fields)->toEqual(TavusFieldSpecs::config())
            ->and($description->personaFields)->toEqual(TavusFieldSpecs::persona());
    });
});

it('is validated by the shared validator, not a copy of it', function () {
    expect(FieldSpecValidator::validate(TavusFieldSpecs::config(), ['replica_id' => 'r', 'persona_id' => 'p'], null, 'config'))->toBe([]);
});
