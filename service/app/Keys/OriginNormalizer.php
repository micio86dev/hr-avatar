<?php

namespace App\Keys;

use InvalidArgumentException;

/**
 * Turns a browser origin into the one canonical form the allowlist stores and the `Origin`
 * header is compared against: lowercase `scheme://host[:port]`, no path, no trailing slash, no
 * default port. A refusal carries a message safe to show to the caller.
 */
final class OriginNormalizer
{
    private const MAX_ORIGINS = 20;

    private const ORIGIN = '/^(https?):\/\/([^\/:?#@\s]+)(?::(\d+))?\/?\z/';

    private const HOST = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)*\z/';

    /** Hosts that may be reached over plain http, for local development. */
    private const LOOPBACK = ['localhost', '127.0.0.1'];

    /**
     * @throws InvalidArgumentException when the value is not an acceptable origin
     */
    public static function normalize(string $origin): string
    {
        $origin = strtolower(trim($origin));

        if (str_contains($origin, '*')) {
            throw new InvalidArgumentException('A wildcard is not allowed in an origin.');
        }

        if (preg_match(self::ORIGIN, $origin, $parts) !== 1 || preg_match(self::HOST, $parts[2]) !== 1) {
            throw new InvalidArgumentException('An origin must look like scheme://host[:port], with no path, query, fragment or credentials.');
        }

        [, $scheme, $host] = $parts;
        $port = isset($parts[3]) ? (int) $parts[3] : null;

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('The origin port must be between 1 and 65535.');
        }

        if ($scheme === 'http' && ! in_array($host, self::LOOPBACK, true)) {
            throw new InvalidArgumentException('An origin must use https; plain http is allowed only for localhost and 127.0.0.1.');
        }

        $isDefaultPort = $port === ($scheme === 'https' ? 443 : 80);

        return "{$scheme}://{$host}".($port === null || $isDefaultPort ? '' : ":{$port}");
    }

    /**
     * @param  list<string>  $origins
     * @return list<string> normalized, without duplicates
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeAll(array $origins): array
    {
        $normalized = array_values(array_unique(array_map(self::normalize(...), $origins)));

        if ($normalized === [] || count($normalized) > self::MAX_ORIGINS) {
            throw new InvalidArgumentException('A publishable key needs 1 to 20 distinct origins.');
        }

        return $normalized;
    }
}
