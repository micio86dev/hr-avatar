<?php

namespace App\AvatarProviders\LiveAvatar;

use App\AvatarProviders\FieldSpec;
use App\AvatarProviders\FieldType;

/**
 * What an avatar config may set for LiveAvatar: exactly the config keys LiveAvatarPayload sends
 * (camelCase, as the config has always spelt them). The voice itself is the config's `voice`, not a
 * setting; the settings below tune a bound external voice and are refused without one.
 *
 * Not offered, because they were never proven against a live call and are not sent: the session
 * duration, the video encoding and the native voice settings. LiveAvatar's Cartesia settings object
 * has `speed` and `model` only, so the ElevenLabs knobs are refused for a Cartesia voice.
 */
final class LiveAvatarFieldSpecs
{
    /**
     * The `voice_settings.model` values offered per engine: the Italian-capable subset of the
     * vendor enums (`sonic-2` and `sonic-turbo` carry no Italian guarantee; `eleven_v3*` honour
     * only `stability`, which would make the other knobs dead).
     *
     * @var array<string, list<string>>
     */
    public const TTS_MODELS = [
        'cartesia' => ['sonic-3.5', 'sonic-3'],
        'elevenlabs' => ['eleven_flash_v2_5', 'eleven_multilingual_v2'],
    ];

    /**
     * @return list<FieldSpec>
     */
    public static function config(): array
    {
        $external = array_keys(self::TTS_MODELS);

        return [
            new FieldSpec('avatarId', FieldType::Text, required: true, catalogueResource: 'avatar'),
            new FieldSpec('interactivityType', FieldType::Select, options: ['CONVERSATIONAL', 'PUSH_TO_TALK']),
            new FieldSpec('videoQuality', FieldType::Select, options: ['very_high', 'high', 'medium', 'low']),
            new FieldSpec('voiceSpeed', FieldType::Number, min: 0.8, max: 1.2, voiceEngines: $external),
            new FieldSpec('voiceStability', FieldType::Number, min: 0, max: 1, voiceEngines: ['elevenlabs']),
            new FieldSpec('voiceSimilarityBoost', FieldType::Number, min: 0, max: 1, voiceEngines: ['elevenlabs']),
            new FieldSpec('voiceStyle', FieldType::Number, min: 0, max: 1, voiceEngines: ['elevenlabs']),
            new FieldSpec('voiceUseSpeakerBoost', FieldType::Checkbox, voiceEngines: ['elevenlabs']),
            new FieldSpec(
                'ttsModelName',
                FieldType::Select,
                options: array_merge(...array_values(self::TTS_MODELS)),
                optionsByVoiceEngine: self::TTS_MODELS,
                voiceEngines: $external,
            ),
        ];
    }

    /**
     * A LiveAvatar persona is the conversation's own context; there is nothing to configure on it.
     *
     * @return list<FieldSpec>
     */
    public static function persona(): array
    {
        return [];
    }
}
