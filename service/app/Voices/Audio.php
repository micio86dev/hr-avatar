<?php

namespace App\Voices;

/**
 * What the service knows about audio it did not make: the type, read from the bytes.
 */
final class Audio
{
    /**
     * Read from the bytes rather than assumed: LiveAvatar does not document the container of its sample.
     */
    public static function contentType(string $audio): string
    {
        return match (true) {
            str_starts_with($audio, 'RIFF') => 'audio/wav',
            str_starts_with($audio, 'OggS') => 'audio/ogg',
            default => 'audio/mpeg',
        };
    }
}
