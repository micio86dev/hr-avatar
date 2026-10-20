<?php

namespace App\Conversations;

/**
 * The address the service is reached at from outside (`HRA_PUBLIC_BASE_URL`), as providers that call
 * back must be given it. A value that is unset, blank or not an http(s) address with a host is no address:
 * a target built from it would be one no provider can reach.
 */
final class PublicBaseUrl
{
    /**
     * The address without a trailing slash, or null when none is usable.
     */
    public static function get(): ?string
    {
        $value = config('hr-avatar.public_base_url');
        $parts = is_string($value) ? parse_url(trim($value)) : false;

        if ($parts === false || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }

        return rtrim(trim((string) $value), '/');
    }
}
