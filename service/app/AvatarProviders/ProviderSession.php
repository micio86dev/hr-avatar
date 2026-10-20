<?php

namespace App\AvatarProviders;

use InvalidArgumentException;

/**
 * The outcome of starting a provider session: the refs the service keeps to tear it down and
 * read it later (server-only), and the join material it hands to the browser.
 */
final readonly class ProviderSession
{
    public function __construct(
        public string $sessionRef,
        public ?string $contextRef,
        public JoinMaterial $joinMaterial,
    ) {
        if ($sessionRef === '') {
            throw new InvalidArgumentException('A provider session needs a ref, or it could never be torn down.');
        }
    }
}
