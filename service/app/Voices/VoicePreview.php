<?php

namespace App\Voices;

use App\AvatarProviders\ProviderException;
use App\Credentials\ProviderAuth;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Produces a short audio sample of one vendor voice, so a voice can be heard before it is bound.
 *
 * The sentence is always the configured one for the language (`hr-avatar.voice_preview.phrases`):
 * callers pass a language, never text, so this cannot be used as a free text-to-speech proxy on an
 * account's or the platform's key. The vendor is the external engine when one is named, else the
 * provider itself; a provider that is not a vendor and has no engine (a stock voice of an avatar
 * provider) has nothing to preview.
 *
 * Audio is cached per credential, vendor, voice, model, phrase version and language, so a repeat costs
 * nothing at the vendor. The credential is part of the key: a voice id only one account's key can use
 * (a clone) must not be played back to another account. A vendor whose sample is its own clip shares
 * one entry across languages. A failure and an unusable answer are never cached.
 */
final class VoicePreview
{
    private const GENERIC = 'generic';

    public function __construct(private readonly VoiceVendorRegistry $vendors) {}

    /**
     * The vendor that would speak: the external engine named, else the provider itself.
     */
    public function vendorFor(string $provider, ?string $ttsEngine): ?VoiceVendor
    {
        return $this->vendors->get($ttsEngine ?? $provider);
    }

    /**
     * @return array{audio: string, content_type: string}
     *
     * @throws ProviderException
     * @throws InvalidArgumentException when no sentence is configured for the language
     */
    public function preview(VoiceVendor $vendor, string $voiceId, string $language, ProviderAuth $auth): array
    {
        $phrase = config("hr-avatar.voice_preview.phrases.{$language}");

        if (! is_string($phrase) || $phrase === '') {
            throw new InvalidArgumentException('No sentence is configured for that language.');
        }

        $key = 'hra:voice-preview:'.hash('sha256', implode('|', [
            $auth->owner, $auth->fingerprint, $vendor->id(), $voiceId, $vendor->model() ?? self::GENERIC,
            (string) config('hr-avatar.voice_preview.phrase_version'), $vendor->speaksPhrase() ? $language : self::GENERIC,
        ]));

        $audio = Cache::get($key);

        if (! is_string($audio) || $audio === '') {
            $audio = $vendor->sample($voiceId, $language, $phrase, $auth);

            // A vendor that answers nothing, a message instead of audio, or far more than a few seconds of speech, is not trusted with the cache.
            if ($audio === '' || strlen($audio) > (int) config('hr-avatar.voice_preview.max_bytes') || ! Audio::isAudio($audio)) {
                throw ProviderException::noResponse();
            }

            Cache::put($key, $audio, (int) config('hr-avatar.voice_preview.cache_seconds'));
        }

        return ['audio' => $audio, 'content_type' => Audio::contentType($audio)];
    }
}
