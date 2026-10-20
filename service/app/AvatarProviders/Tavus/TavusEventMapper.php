<?php

namespace App\AvatarProviders\Tavus;

use App\AvatarProviders\ProviderEvent;
use App\AvatarProviders\ProviderEventKind;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * Turns what Tavus says about a conversation into the service's own provider event. Tavus calls
 * back with `{conversation_id, event_type, message_type, timestamp, properties}`, and carries the
 * same events in a conversation read with `verbose=true`.
 */
final class TavusEventMapper
{
    private const KINDS = [
        'system.pal_joined' => ProviderEventKind::Joined,
        // The legacy name Tavus still sends beside the current one.
        'system.replica_joined' => ProviderEventKind::Joined,
        'system.shutdown' => ProviderEventKind::Ended,
        'application.transcription_ready' => ProviderEventKind::TranscriptReady,
    ];

    /**
     * One event per callback, or none when the body names no conversation or no event (there is
     * nothing to key it on). An event that is not one of the four above is still reported, as
     * ignored, so it is recorded and answered like any other.
     *
     * @param  array<string, mixed>  $payload
     * @return list<ProviderEvent>
     */
    public static function map(array $payload): array
    {
        $conversationId = $payload['conversation_id'] ?? null;
        $type = $payload['event_type'] ?? null;

        if (! is_string($conversationId) || $conversationId === '' || ! is_string($type) || $type === '') {
            return [];
        }

        $timestamp = $payload['timestamp'] ?? null;
        $kind = self::KINDS[$type] ?? ProviderEventKind::Ignored;

        $properties = $payload['properties'] ?? null;
        $endReason = $kind === ProviderEventKind::Ended
            ? (self::endReason(is_array($properties) ? $properties : []) ?? 'unknown')
            : null;

        return [new ProviderEvent(
            provider: 'tavus',
            providerRef: $conversationId,
            kind: $kind,
            occurredAt: (self::time($timestamp) ?? now())->toDateTimeImmutable(),
            idempotencyKey: hash('sha256', $conversationId.'|'.$type.'|'.(is_string($timestamp) ? $timestamp : '')),
            providerEndReason: $endReason,
            payload: $payload,
        )];
    }

    /**
     * Why a conversation shut down: Tavus documents `shutdown_reason` in one place and shows
     * `reason` in another, so both are read.
     *
     * @param  array<array-key, mixed>  $properties
     */
    public static function endReason(array $properties): ?string
    {
        foreach (['shutdown_reason', 'reason'] as $key) {
            $value = $properties[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** A time Tavus wrote, or null when it is not one (the caller decides what a missing time means). */
    public static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            // An offset in the string wins; one without it is UTC, not the application's timezone.
            return Carbon::parse($value, 'UTC');
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
