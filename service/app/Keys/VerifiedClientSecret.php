<?php

namespace App\Keys;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The claims of a client secret whose signature, issuer and audience held. Expiry is not
 * checked at parse time so that the caller can tell an expired token from a forged one.
 */
final readonly class VerifiedClientSecret
{
    public function __construct(
        public string $conversationId,
        public string $accountId,
        public string $jti,
        public CarbonImmutable $expiresAt,
    ) {}

    /** The expiry instant itself is already too late. */
    public function isExpired(): bool
    {
        return $this->expiresAt->lte(Carbon::now());
    }
}
