<?php

namespace App\Credentials;

/**
 * The only part of a stored key an API response may show. A key too short for four characters
 * to be a small part of it (under 12) shows nothing.
 */
final class LastFour
{
    private const MIN_LENGTH_TO_REVEAL = 12;

    public static function of(#[\SensitiveParameter] string $key): ?string
    {
        return mb_strlen($key) < self::MIN_LENGTH_TO_REVEAL ? null : mb_substr($key, -4);
    }
}
