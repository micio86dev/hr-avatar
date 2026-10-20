<?php

namespace App\AvatarProviders\LiveAvatar;

use App\AvatarProviders\ConversationSpec;

/**
 * The bodies the LiveAvatar adapter sends, as pure functions: "does the avatar id an operator set
 * end up in the request" is one assertion, not an integration test.
 *
 * Two rules run through it. Unset means absent, never null: a null tells the provider "use null",
 * an absent key tells it "use your default". And only an allowlist of config fields is ever sent,
 * each one only if it has the type the provider takes; there is no way to widen the list at run time.
 *
 * The allowlist is the set LiveAvatar was proven to accept from the demonstrated-working call:
 * `avatar_id`, `interactivity_type` and `video_settings.quality`, plus, for a voice bound from an
 * external vendor, the `voice_settings` object. The knobs a testbed also had (session duration,
 * video encoding, native voice settings) were never proven against a live call, and sending an
 * unproven field risks a 422 on every start, so they stay out. Every other key of the config is ignored.
 */
final class LiveAvatarPayload
{
    /** The model pinned in `voice_settings.model` for an external voice, so a vendor default cannot move a live config. */
    private const DEFAULT_TTS_MODEL = ['cartesia' => 'sonic-3.5', 'elevenlabs' => 'eleven_flash_v2_5'];

    /** LiveAvatar's discriminator values for `voice_settings.provider`. */
    private const VOICE_PROVIDER = ['cartesia' => 'cartesia', 'elevenlabs' => 'elevenLabs'];

    /**
     * The `POST /v1/contexts` body: exactly the name, the system prompt and the opening text.
     *
     * @return array{name: string, prompt: string, opening_text?: string}
     */
    public static function context(string $name, ConversationSpec $spec): array
    {
        $body = ['name' => $name, 'prompt' => $spec->systemPrompt];

        if ($spec->openingText !== null && $spec->openingText !== '') {
            $body['opening_text'] = $spec->openingText;
        }

        return $body;
    }

    /**
     * The `POST /v1/sessions/token` body. Avatar identity lives here, never on the context.
     *
     * `llm_configuration_id` is a top-level field of this call and exists nowhere else: nested under
     * `avatar_persona` LiveAvatar silently ignores it, so the avatar would look bound and use
     * HeyGen's default model (proven live, 2026-10-08). `mode`, `is_sandbox` and the persona's
     * context and language are protocol constants and the conversation's own; no config key can set them.
     *
     * @param  array<string, mixed>  $config  the avatar config's own settings
     * @param  string|null  $voiceId  the LiveAvatar voice id: the stock voice's, or the bound external voice's
     * @param  string|null  $engine  the external vendor (`cartesia`, `elevenlabs`) the voice is bound from, else null
     * @return array<string, mixed>
     */
    public static function sessionToken(array $config, string $language, string $contextId, ?string $llmConfigurationId, ?string $voiceId, ?string $engine): array
    {
        $body = [];

        self::put($body, 'avatar_id', self::text($config, 'avatarId'));
        $body['interactivity_type'] = self::text($config, 'interactivityType') ?? 'CONVERSATIONAL';
        $body['video_settings'] = ['quality' => self::text($config, 'videoQuality') ?? 'low'];
        $body['mode'] = 'FULL';
        $body['is_sandbox'] = false;

        $persona = [];
        self::put($persona, 'voice_id', $voiceId);
        $body['avatar_persona'] = [...$persona, 'language' => $language, 'context_id' => $contextId];

        if ($engine !== null && isset(self::VOICE_PROVIDER[$engine])) {
            $body['voice_settings'] = self::voiceSettings($config, $engine);
        }

        if ($llmConfigurationId !== null && $llmConfigurationId !== '') {
            $body['llm_configuration_id'] = $llmConfigurationId;
        }

        return $body;
    }

    /**
     * LiveAvatar's `voice_settings` is a union discriminated by `provider`; flat keys without it are
     * exactly what it ignores. Cartesia's object has `speed` and `model` only: the ElevenLabs knobs
     * are not sent to it.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function voiceSettings(array $config, string $engine): array
    {
        $settings = ['provider' => self::VOICE_PROVIDER[$engine]];

        self::put($settings, 'speed', self::number($config, 'voiceSpeed'));

        if ($engine === 'elevenlabs') {
            self::put($settings, 'stability', self::number($config, 'voiceStability'));
            self::put($settings, 'similarity_boost', self::number($config, 'voiceSimilarityBoost'));
            self::put($settings, 'style', self::number($config, 'voiceStyle'));
            self::put($settings, 'use_speaker_boost', is_bool($config['voiceUseSpeakerBoost'] ?? null) ? $config['voiceUseSpeakerBoost'] : null);
        }

        $settings['model'] = self::text($config, 'ttsModelName') ?? self::DEFAULT_TTS_MODEL[$engine];

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function text(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function number(array $config, string $key): int|float|null
    {
        $value = $config[$key] ?? null;

        return is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private static function put(array &$target, string $key, mixed $value): void
    {
        if ($value !== null) {
            $target[$key] = $value;
        }
    }
}
