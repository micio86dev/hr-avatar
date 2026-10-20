<?php

namespace App\AvatarProviders;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Something that happened in a provider session, normalized: a callback and a polled observation
 * become the same value, which the service stores once (by idempotency key) and applies.
 */
final readonly class ProviderEvent
{
    /**
     * @param  array<string, mixed>  $payload  what the provider sent, kept for the receipt
     *
     * @throws MalformedCallback when the time is out of range
     */
    public function __construct(
        public string $provider,
        public string $providerRef,
        public ProviderEventKind $kind,
        public DateTimeImmutable $occurredAt,
        public string $idempotencyKey,
        public ?string $providerEndReason,
        public array $payload,
    ) {
        // The receipt stores the time as a timestamp, in UTC; a year outside what one can hold would fail the insert
        // (a 500). The year is judged in UTC, whatever offset the provider wrote the time in.
        $year = (int) $occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y');

        if ($year < 1 || $year > 9999) {
            throw new MalformedCallback('The event time is out of range.');
        }
    }
}
