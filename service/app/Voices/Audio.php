<?php

namespace App\Voices;

/**
 * What the service knows about audio it did not make: the type, read from the bytes.
 */
final class Audio
{
    /**
     * Whether the bytes can be audio and not a message: every audio container opens with a binary header (a control
     * byte, or bytes that are no UTF-8 text), so a body that opens with plain text, accented letters included (a JSON
     * or HTML error envelope, a sentence), is not one. A vendor that answers such a body with a success status must
     * not have it kept as a sample.
     */
    public static function isAudio(string $bytes): bool
    {
        // mb_strcut cuts on a character boundary, so text that straddles the 512th byte is still valid text (AudioTest).
        $start = mb_strcut($bytes, 0, 512, 'UTF-8');
        $isText = mb_check_encoding($start, 'UTF-8') && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $start) !== 1;

        return ! $isText;
    }

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
