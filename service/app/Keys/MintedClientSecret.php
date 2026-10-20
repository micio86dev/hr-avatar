<?php

namespace App\Keys;

use Carbon\CarbonImmutable;

/**
 * A freshly minted client secret: shown to the caller once, in the conversation create response.
 */
final readonly class MintedClientSecret
{
    public function __construct(
        #[\SensitiveParameter] public string $token,
        public string $jti,
        public CarbonImmutable $expiresAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]', 'jti' => $this->jti, 'expiresAt' => $this->expiresAt];
    }
}
