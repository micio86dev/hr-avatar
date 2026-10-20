<?php

namespace App\AvatarProviders\Tavus;

/**
 * The spelled-out language vocabulary Tavus expects.
 *
 * Tavus accepts a wrong value and ignores it silently, so the avatar would answer in the wrong
 * language and nobody would trace it to a language mapping. That is why an unmapped locale passes
 * through unchanged instead of falling back to a default: a confidently wrong value cannot be
 * noticed, an unrecognised one can.
 */
final class TavusLanguage
{
    public static function forWire(string $locale): string
    {
        return match ($locale) {
            'it' => 'italian',
            'en' => 'english',
            default => $locale,
        };
    }
}
