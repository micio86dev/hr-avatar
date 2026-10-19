<?php

use App\AvatarProviders\FieldSpec;
use App\AvatarProviders\FieldType;
use App\AvatarProviders\LiveAvatar\LiveAvatarFieldSpecs;
use App\AvatarProviders\LiveAvatar\LiveAvatarProvider;
use App\AvatarProviders\VoiceBinding;

/**
 * @param  array<string, mixed>  $config
 * @return list<string>
 */
function liveAvatarErrors(array $config, ?VoiceBinding $voice = null, array $persona = []): array
{
    return array_map(
        fn ($error) => "{$error->field}:{$error->code}",
        app(LiveAvatarProvider::class)->validateConfig($config, $voice, $persona),
    );
}

describe('LiveAvatar field specs', function () {
    it('declares the keys the payload reads, in camelCase', function () {
        $keys = array_map(fn (FieldSpec $spec) => $spec->key, LiveAvatarFieldSpecs::config());

        expect($keys)->toBe([
            'avatarId', 'interactivityType', 'videoQuality', 'voiceSpeed', 'voiceStability',
            'voiceSimilarityBoost', 'voiceStyle', 'voiceUseSpeakerBoost', 'ttsModelName',
        ]);
    });

    it('does not offer a knob the adapter never sends', function () {
        $keys = array_map(fn (FieldSpec $spec) => $spec->key, LiveAvatarFieldSpecs::config());

        expect($keys)->not->toContain('maxSessionDurationSec')->not->toContain('videoEncoding')->not->toContain('voiceId')->not->toContain('ttsEngine');
    });

    it('requires the avatar and lists the catalogue that backs it', function () {
        $avatar = LiveAvatarFieldSpecs::config()[0];

        expect([$avatar->key, $avatar->type, $avatar->required, $avatar->catalogueResource])->toBe(['avatarId', FieldType::Text, true, 'avatar']);
    });

    it('takes no persona knob: its persona is the conversation context', function () {
        expect(LiveAvatarFieldSpecs::persona())->toBe([]);
    });

    it('names the Italian-capable models each engine takes', function () {
        expect(LiveAvatarFieldSpecs::TTS_MODELS)->toBe([
            'cartesia' => ['sonic-3.5', 'sonic-3'],
            'elevenlabs' => ['eleven_flash_v2_5', 'eleven_multilingual_v2'],
        ]);
    });
});

describe('LiveAvatar validateConfig', function () {
    it('accepts the avatar alone', function () {
        expect(liveAvatarErrors(['avatarId' => 'av_1']))->toBe([]);
    });

    it('requires the avatar', function () {
        expect(liveAvatarErrors([]))->toBe(['config.avatarId:required']);
    });

    it('rejects a key outside the specification, among them the retired testbed ones', function (string $key) {
        expect(liveAvatarErrors(['avatarId' => 'a', $key => 'x']))->toBe(["config.{$key}:unknown"]);
    })->with(['voiceId', 'ttsEngine', 'maxSessionDurationSec', 'videoEncoding', 'nonsense']);

    it('validates the session settings', function (array $extra, array $expected) {
        expect(liveAvatarErrors(['avatarId' => 'a', ...$extra]))->toBe($expected);
    })->with([
        'conversational' => [['interactivityType' => 'CONVERSATIONAL'], []],
        'push to talk' => [['interactivityType' => 'PUSH_TO_TALK'], []],
        'bad interactivity' => [['interactivityType' => 'WALKIE'], ['config.interactivityType:enum']],
        'every quality' => [['videoQuality' => 'very_high'], []],
        'bad quality' => [['videoQuality' => 'ultra'], ['config.videoQuality:enum']],
    ]);

    it('accepts the voice settings of an ElevenLabs voice', function () {
        $errors = liveAvatarErrors(
            ['avatarId' => 'a', 'voiceSpeed' => 1.1, 'voiceStability' => 0.4, 'voiceSimilarityBoost' => 0.9, 'voiceStyle' => 0, 'voiceUseSpeakerBoost' => true, 'ttsModelName' => 'eleven_flash_v2_5'],
            new VoiceBinding('elevenlabs', 'v-1'),
        );

        expect($errors)->toBe([]);
    });

    it('accepts speed and a model for a Cartesia voice', function () {
        expect(liveAvatarErrors(['avatarId' => 'a', 'voiceSpeed' => 0.8, 'ttsModelName' => 'sonic-3'], new VoiceBinding('cartesia', 'v')))->toBe([]);
    });

    it('refuses the knobs Cartesia has no setting for', function (string $key, mixed $value) {
        expect(liveAvatarErrors(['avatarId' => 'a', $key => $value], new VoiceBinding('cartesia', 'v')))->toBe(["config.{$key}:requires_voice_engine"]);
    })->with([
        ['voiceStability', 0.5],
        ['voiceSimilarityBoost', 0.5],
        ['voiceStyle', 0.5],
        ['voiceUseSpeakerBoost', true],
    ]);

    it('refuses every voice setting when the voice is not an external one', function (?VoiceBinding $voice) {
        $errors = liveAvatarErrors(['avatarId' => 'a', 'voiceSpeed' => 1, 'ttsModelName' => 'sonic-3'], $voice);

        expect($errors)->toBe(['config.voiceSpeed:requires_voice_engine', 'config.ttsModelName:requires_voice_engine']);
    })->with([
        'no voice' => [null],
        'stock voice' => [new VoiceBinding('provider', 'stock')],
    ]);

    it('keeps the speed within what LiveAvatar takes', function (int|float $speed, bool $valid) {
        $errors = liveAvatarErrors(['avatarId' => 'a', 'voiceSpeed' => $speed], new VoiceBinding('cartesia', 'v'));

        expect($errors)->toBe($valid ? [] : ['config.voiceSpeed:range']);
    })->with([[0.79, false], [0.8, true], [1.2, true], [1.21, false]]);

    it('rejects a model of the other engine', function () {
        expect(liveAvatarErrors(['avatarId' => 'a', 'ttsModelName' => 'eleven_flash_v2_5'], new VoiceBinding('cartesia', 'v')))->toBe(['config.ttsModelName:enum']);
    });

    it('rejects the models LiveAvatar does not guarantee Italian for', function (string $model) {
        expect(liveAvatarErrors(['avatarId' => 'a', 'ttsModelName' => $model], new VoiceBinding('cartesia', 'v')))->toBe(['config.ttsModelName:enum']);
    })->with(['sonic-2', 'sonic-turbo']);

    it('accepts all three voice engines and no others', function () {
        foreach (['provider', 'cartesia', 'elevenlabs'] as $engine) {
            expect(liveAvatarErrors(['avatarId' => 'a'], new VoiceBinding($engine, 'v')))->toBe([]);
        }

        expect(liveAvatarErrors(['avatarId' => 'a'], new VoiceBinding('azure', 'v')))->toBe(['voice.engine:unsupported']);
    });

    it('takes no persona', function () {
        expect(liveAvatarErrors(['avatarId' => 'a'], null, ['anything' => 1]))->toBe(['persona.anything:unknown']);
    });

    it('is described without a provider call', function () {
        $description = app(LiveAvatarProvider::class)->describe();

        expect($description->resources)->toBe(['avatar', 'voice'])
            ->and($description->voiceEngines)->toBe(['provider', 'cartesia', 'elevenlabs'])
            ->and($description->personaFields)->toBe([]);
    });
});
