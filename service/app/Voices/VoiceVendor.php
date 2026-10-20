<?php

namespace App\Voices;

use App\AvatarProviders\CatalogueEntry;
use App\AvatarProviders\ProviderException;
use App\Credentials\ProviderAuth;

/**
 * A provider of voices: it lists them and can produce a short audio sample of one. Cartesia and
 * ElevenLabs are vendors an avatar provider can route its voice through; LiveAvatar is both an
 * avatar provider and a vendor of its own voices. The vendor's id is also the credential provider
 * its calls are made with.
 */
interface VoiceVendor
{
    public function id(): string;

    /**
     * The model a synthesized sample is made with, part of the audio's cache key; null for a vendor
     * whose sample is its own clip.
     */
    public function model(): ?string;

    /**
     * Whether the sample speaks the configured phrase in the requested language (true) or is one clip
     * the vendor has for the voice, whatever the language (false).
     */
    public function speaksPhrase(): bool;

    /**
     * @return list<CatalogueEntry>
     *
     * @throws ProviderException
     */
    public function voices(ProviderAuth $auth): array;

    /**
     * The audio bytes of a sample of one voice.
     *
     * @param  string  $phrase  what a synthesizing vendor speaks; ignored by one that has its own clip
     *
     * @throws ProviderException
     */
    public function sample(string $voiceId, string $language, string $phrase, ProviderAuth $auth): string;
}
