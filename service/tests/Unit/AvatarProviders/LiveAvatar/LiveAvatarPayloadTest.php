<?php

use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\LiveAvatar\LiveAvatarPayload;

function laPayloadSpec(?string $opening = 'Hello!'): ConversationSpec
{
    return new ConversationSpec('Be kind.', $opening, 'it', 600, new stdClass);
}

/**
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function laToken(array $config = [], ?string $voiceId = null, ?string $engine = null, ?string $llmConfigurationId = null, string $language = 'it'): array
{
    return LiveAvatarPayload::sessionToken($config, $language, 'ctx_1', $llmConfigurationId, $voiceId, $engine);
}

describe('context body', function () {
    it('carries the name, the system prompt and the opening text, nothing else', function () {
        expect(LiveAvatarPayload::context('hra-conv_1-X', laPayloadSpec()))
            ->toBe(['name' => 'hra-conv_1-X', 'prompt' => 'Be kind.', 'opening_text' => 'Hello!']);
    });

    it('leaves the opening text out when there is none: unset means absent, never null', function (?string $opening) {
        expect(LiveAvatarPayload::context('n', laPayloadSpec($opening)))->toBe(['name' => 'n', 'prompt' => 'Be kind.']);
    })->with(['null' => [null]]);

    it('keeps an empty opening text out as well, since an empty greeting is no greeting', function () {
        expect(LiveAvatarPayload::context('n', laPayloadSpec('')))->not->toHaveKey('opening_text');
    });
});

describe('session token body', function () {
    it('always sends the protocol constants, the language and the context, and the two proven defaults', function () {
        expect(laToken())->toBe([
            'interactivity_type' => 'CONVERSATIONAL',
            'video_settings' => ['quality' => 'low'],
            'mode' => 'FULL',
            'is_sandbox' => false,
            'avatar_persona' => ['language' => 'it', 'context_id' => 'ctx_1'],
        ]);
    });

    it('takes the spoken language from the conversation, not from the config', function (string $language) {
        expect(laToken(['language' => 'xx', 'avatarLanguage' => 'xx'], language: $language)['avatar_persona']['language'])->toBe($language);
    })->with(['it', 'en']);

    it('lets the allowlisted config fields set the avatar, the interactivity and the video quality', function () {
        $body = laToken(['avatarId' => 'av-1', 'interactivityType' => 'PUSH_TO_TALK', 'videoQuality' => 'high']);

        expect($body['avatar_id'])->toBe('av-1')
            ->and($body['interactivity_type'])->toBe('PUSH_TO_TALK')
            ->and($body['video_settings'])->toBe(['quality' => 'high']);
    });

    it('sends the stock voice id of the persona when one is given', function () {
        expect(laToken(voiceId: 'stock-voice')['avatar_persona'])->toBe(['voice_id' => 'stock-voice', 'language' => 'it', 'context_id' => 'ctx_1']);
    });

    it('forwards only allowlisted fields: an unknown key never reaches the token body', function () {
        $body = laToken([
            'avatarId' => 'av-1',
            'maxSessionDurationSec' => 600,
            'videoEncoding' => 'H264',
            'voiceSpeed' => 1.2,
            'foo' => 'bar',
            'video_settings' => ['encoding' => 'VP8'],
            'avatar_persona' => ['voice_id' => 'smuggled'],
            'voice_settings' => ['speed' => 1.2],
        ]);

        expect($body)->toBe([
            'avatar_id' => 'av-1',
            'interactivity_type' => 'CONVERSATIONAL',
            'video_settings' => ['quality' => 'low'],
            'mode' => 'FULL',
            'is_sandbox' => false,
            'avatar_persona' => ['language' => 'it', 'context_id' => 'ctx_1'],
        ]);
    });

    it('cannot be talked out of its protocol constants by the config', function () {
        $body = laToken(['mode' => 'LITE', 'is_sandbox' => true, 'llm_configuration_id' => 'cfg_evil', 'llmConfigurationId' => 'cfg_evil']);

        expect($body['mode'])->toBe('FULL')
            ->and($body['is_sandbox'])->toBeFalse()
            ->and($body)->not->toHaveKey('llm_configuration_id');
    });

    it('drops an allowlisted field of the wrong type instead of sending it', function (string $key, mixed $value, string $path) {
        expect(data_get(laToken([$key => $value]), $path))->toBe(match ($key) {
            'interactivityType' => 'CONVERSATIONAL',
            'videoQuality' => 'low',
            default => null,
        });
    })->with([
        'avatar id as a number' => ['avatarId', 12, 'avatar_id'],
        'avatar id empty' => ['avatarId', '', 'avatar_id'],
        'interactivity as a list' => ['interactivityType', ['x'], 'interactivity_type'],
        'quality as a number' => ['videoQuality', 3, 'video_settings.quality'],
    ]);

    it('puts the language-model configuration at the top level, never under the persona', function () {
        $body = laToken(llmConfigurationId: 'cfg_1');

        expect($body['llm_configuration_id'])->toBe('cfg_1')
            ->and($body['avatar_persona'])->not->toHaveKey('llm_configuration_id');
    });

    it('sends no language-model configuration when there is none', function (?string $id) {
        expect(laToken(llmConfigurationId: $id))->not->toHaveKey('llm_configuration_id');
    })->with(['null' => [null], 'empty' => ['']]);
});

describe('external voice', function () {
    it('describes a Cartesia voice with the discriminated settings object: provider, speed and the pinned model', function () {
        $body = laToken(['voiceSpeed' => 1.1, 'voiceStability' => 0.5, 'voiceStyle' => 0.2], voiceId: 'bound-1', engine: 'cartesia');

        expect($body['avatar_persona']['voice_id'])->toBe('bound-1')
            ->and($body['voice_settings'])->toBe(['provider' => 'cartesia', 'speed' => 1.1, 'model' => 'sonic-3.5']);
    });

    it('describes an ElevenLabs voice with all of its knobs', function () {
        $body = laToken([
            'voiceSpeed' => 1.0, 'voiceStability' => 0.4, 'voiceSimilarityBoost' => 0.7, 'voiceStyle' => 0.1, 'voiceUseSpeakerBoost' => true,
        ], voiceId: 'bound-2', engine: 'elevenlabs');

        expect($body['voice_settings'])->toBe([
            'provider' => 'elevenLabs', 'speed' => 1.0, 'stability' => 0.4, 'similarity_boost' => 0.7, 'style' => 0.1,
            'use_speaker_boost' => true, 'model' => 'eleven_flash_v2_5',
        ]);
    });

    it('lets the config choose the voice model, and pins a default otherwise', function (string $engine, array $config, string $model) {
        expect(laToken($config, voiceId: 'bound', engine: $engine)['voice_settings']['model'])->toBe($model);
    })->with([
        'cartesia default' => ['cartesia', [], 'sonic-3.5'],
        'cartesia chosen' => ['cartesia', ['ttsModelName' => 'sonic-3'], 'sonic-3'],
        'elevenlabs default' => ['elevenlabs', [], 'eleven_flash_v2_5'],
        'elevenlabs chosen' => ['elevenlabs', ['ttsModelName' => 'eleven_multilingual_v2'], 'eleven_multilingual_v2'],
        'a model that is not a string falls back to the default' => ['cartesia', ['ttsModelName' => 7], 'sonic-3.5'],
    ]);

    it('sends no voice settings for the provider\'s own voice, whatever the config holds', function () {
        expect(laToken(['voiceSpeed' => 1.2, 'ttsModelName' => 'sonic-3'], voiceId: 'stock', engine: null))->not->toHaveKey('voice_settings');
    });

    it('drops a knob of the wrong type', function () {
        $settings = laToken(['voiceSpeed' => 'fast', 'voiceUseSpeakerBoost' => 'yes'], voiceId: 'bound', engine: 'elevenlabs')['voice_settings'];

        expect($settings)->toBe(['provider' => 'elevenLabs', 'model' => 'eleven_flash_v2_5']);
    });
});

it('does not read configuration or the environment: the old widening of the allowlist is gone', function () {
    $source = file_get_contents(app_path('AvatarProviders/LiveAvatar/LiveAvatarPayload.php'));

    expect($source)->not->toContain('config(')->not->toContain('env(')->not->toContain('extra_token_fields');
});
