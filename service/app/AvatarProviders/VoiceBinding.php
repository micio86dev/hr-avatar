<?php

namespace App\AvatarProviders;

/**
 * The voice an avatar config speaks with: the engine (`provider` is the provider's own stock voice,
 * the others bind a voice from an external vendor) and the vendor's voice id.
 */
final readonly class VoiceBinding
{
    public const PROVIDER_ENGINE = 'provider';

    /** @var list<string> */
    public const ENGINES = [self::PROVIDER_ENGINE, 'cartesia', 'elevenlabs'];

    public function __construct(public string $engine, public string $voiceId) {}

    /**
     * The binding of a stored or validated `voice` object, or null when there is none to speak of.
     *
     * @param  array<string, mixed>|null  $voice
     */
    public static function fromArray(?array $voice): ?self
    {
        $engine = $voice['engine'] ?? null;
        $voiceId = $voice['voice_id'] ?? null;

        return is_string($engine) && is_string($voiceId) ? new self($engine, $voiceId) : null;
    }

    public function isExternal(): bool
    {
        return $this->engine !== self::PROVIDER_ENGINE;
    }
}
