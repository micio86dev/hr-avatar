<?php

namespace App\AvatarProviders\LiveAvatar;

use App\AvatarProviders\ProviderException;
use App\Credentials\ProviderAuth;
use App\Voices\VoiceVendor;

/**
 * LiveAvatar as a voice vendor: the voices its catalogue lists, and the one clip it has for each
 * (`GET /voices/{id}/preview`, base64 audio). That clip is generic: it does not speak the configured
 * phrase and does not depend on the language.
 */
final class LiveAvatarVoices implements VoiceVendor
{
    public function __construct(private readonly LiveAvatarApi $api, private readonly LiveAvatarCatalogue $catalogue) {}

    public function id(): string
    {
        return LiveAvatarApi::PROVIDER_ID;
    }

    public function model(): ?string
    {
        return null;
    }

    public function speaksPhrase(): bool
    {
        return false;
    }

    public function voices(ProviderAuth $auth): array
    {
        return $this->catalogue->list('voice', $auth);
    }

    public function sample(string $voiceId, string $language, string $phrase, ProviderAuth $auth): string
    {
        $response = $this->api->orFail($this->api->call($auth, 'GET', '/voices/'.rawurlencode($voiceId).'/preview', timeout: (int) config('hr-avatar.voice_preview.timeout_seconds')));

        $encoded = $response->json('data.audio_base64');
        $decoded = is_string($encoded) ? base64_decode(preg_replace('#^data:[^,]*,#', '', $encoded) ?? '', true) : false;

        return $decoded === false ? throw ProviderException::noResponse() : $decoded;
    }
}
