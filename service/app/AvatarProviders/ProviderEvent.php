<?php

namespace App\AvatarProviders;

use DateTimeImmutable;

/**
 * Something that happened in a provider session, normalized: a callback and a polled observation
 * become the same value, which the service stores once (by idempotency key) and applies.
 */
final readonly class ProviderEvent
{
    /**
     * @param  array<string, mixed>  $payload  what the provider sent, kept for the receipt
     */
    public function __construct(
        public string $provider,
        public string $providerRef,
        public ProviderEventKind $kind,
        public DateTimeImmutable $occurredAt,
        public string $idempotencyKey,
        public ?string $providerEndReason,
        public array $payload,
    ) {}
}
