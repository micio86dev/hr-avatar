<?php

namespace App\Keys;

use InvalidArgumentException;

/**
 * Generates, hashes and recognizes API keys. A key is shown once at creation; only its sha256
 * and an identifying prefix are ever stored.
 */
final class KeyGenerator
{
    /** Hex characters of the random part that stay visible in the stored prefix. */
    private const VISIBLE_RANDOM_CHARS = 8;

    private const FORMAT = '/^hra_(sk|pk)_(live|test)_([0-9a-f]+)\z/';

    public static function generate(KeyType $type, KeyMode $mode): string
    {
        return $type->marker($mode).bin2hex(random_bytes($type->randomBytes()));
    }

    public static function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    /**
     * The marker plus the first 8 hex characters: enough to find a key's row, never to authenticate.
     *
     * @throws InvalidArgumentException when the key is malformed
     */
    public static function prefixOf(string $rawKey): string
    {
        $parsed = self::parse($rawKey) ?? throw new InvalidArgumentException('A key prefix needs a well-formed key.');
        $marker = $parsed->type->marker($parsed->mode);

        return $marker.substr($rawKey, strlen($marker), self::VISIBLE_RANDOM_CHARS);
    }

    /**
     * The type and mode of a well-formed key, or null for anything else. Pure string work, so a
     * malformed key is rejected before any database lookup.
     */
    public static function parse(string $rawKey): ?ParsedKey
    {
        if (preg_match(self::FORMAT, $rawKey, $matches) !== 1) {
            return null;
        }

        $type = $matches[1] === 'sk' ? KeyType::Secret : KeyType::Publishable;

        if (strlen($matches[3]) !== $type->randomBytes() * 2) {
            return null;
        }

        return new ParsedKey($type, KeyMode::from($matches[2]));
    }
}
