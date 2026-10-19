<?php

namespace App\AvatarProviders\Tavus;

use App\AvatarProviders\FieldSpec;
use App\AvatarProviders\FieldType;

/**
 * What an avatar config may set for Tavus. `config` holds what a conversation sends (the replica,
 * the persona and the left-timeout, the keys TavusPayload reads) plus the voice model; `persona`
 * holds the knobs that live on the Tavus persona and reach it only through TavusPersonaSync. Sent
 * on a conversation they would be ignored without an error, which is why they are a separate object.
 *
 * Not offered, because nothing sends them: recording, captions, audio-only and a call duration (the
 * conversation's own `max_duration_seconds` is the duration). The language model is not a knob
 * either: the avatar config's `llm_model_id` binding writes that persona path.
 */
final class TavusFieldSpecs
{
    /**
     * The persona TTS models per external engine (`layers.tts.tts_model_name`).
     * `sonic-2` has no Italian and is never offered.
     *
     * @var array<string, list<string>>
     */
    public const TTS_MODELS = [
        'cartesia' => ['sonic-3', 'sonic-3.5', 'sonic-3.6'],
        'elevenlabs' => ['eleven_multilingual_v2', 'eleven_turbo_v2_5', 'eleven_flash_v2_5'],
    ];

    /**
     * The model sent when a voice is bound without one: the persona update replaces the whole
     * `layers` node, so an omitted model falls back to Tavus's own default, which may not speak
     * Italian. Each is the first Italian-capable model of its engine.
     *
     * @var array<string, string>
     */
    public const DEFAULT_TTS_MODEL = ['cartesia' => 'sonic-3', 'elevenlabs' => 'eleven_multilingual_v2'];

    /** @var list<string> */
    private const LEVELS = ['low', 'medium', 'high'];

    /**
     * @return list<FieldSpec>
     */
    public static function config(): array
    {
        return [
            new FieldSpec('replica_id', FieldType::Text, required: true, catalogueResource: 'replica'),
            new FieldSpec('persona_id', FieldType::Text, required: true, catalogueResource: 'pal'),
            new FieldSpec('participant_left_timeout', FieldType::Integer, min: 0, max: 3600),
            new FieldSpec(
                'tts_model_name',
                FieldType::Select,
                options: array_merge(...array_values(self::TTS_MODELS)),
                optionsByVoiceEngine: self::TTS_MODELS,
                voiceEngines: array_keys(self::TTS_MODELS),
            ),
        ];
    }

    /**
     * @return list<FieldSpec>
     */
    public static function persona(): array
    {
        return [
            new FieldSpec('llm_temperature', FieldType::Number, min: 0, max: 2),
            new FieldSpec('llm_speculative_inference', FieldType::Checkbox),
            new FieldSpec('turn_taking_patience', FieldType::Select, options: self::LEVELS),
            new FieldSpec('interruptibility', FieldType::Select, options: self::LEVELS),
            new FieldSpec('voice_isolation', FieldType::Select, options: ['near', 'off']),
            new FieldSpec('idle_engagement', FieldType::Select, options: ['off', 'patient', 'eager']),
        ];
    }
}
