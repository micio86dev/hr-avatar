<?php

use App\AvatarProviders\Ingestion;
use App\AvatarProviders\ProviderEventKind;
use App\AvatarProviders\Tavus\TavusEventMapper;
use App\AvatarProviders\Tavus\TavusProvider;

/**
 * @param  array<string, mixed>  $properties
 * @return array<string, mixed>
 */
function tavusCallback(string $type, array $properties = [], string $timestamp = '2026-10-19T12:10:00.000000Z'): array
{
    return [
        'properties' => $properties,
        'conversation_id' => 'c123',
        'webhook_url' => 'https://hra.example.test/webhooks/providers/tavus/TOKEN',
        'event_type' => $type,
        'message_type' => str_starts_with($type, 'system.') ? 'system' : 'application',
        'timestamp' => $timestamp,
    ];
}

it('maps the participant joining, under its current and its legacy name', function (string $type) {
    $events = TavusEventMapper::map(tavusCallback($type));

    expect($events)->toHaveCount(1)
        ->and($events[0]->provider)->toBe('tavus')
        ->and($events[0]->providerRef)->toBe('c123')
        ->and($events[0]->kind)->toBe(ProviderEventKind::Joined)
        ->and($events[0]->providerEndReason)->toBeNull()
        ->and($events[0]->occurredAt->format('Y-m-d\TH:i:s'))->toBe('2026-10-19T12:10:00');
})->with(['system.pal_joined', 'system.replica_joined']);

it('maps a shutdown to an end carrying the reason Tavus gives, wherever it gives it', function (array $properties, string $reason) {
    $events = TavusEventMapper::map(tavusCallback('system.shutdown', $properties));

    expect($events[0]->kind)->toBe(ProviderEventKind::Ended)->and($events[0]->providerEndReason)->toBe($reason);
})->with([
    'shutdown_reason' => [['shutdown_reason' => 'participant_left'], 'participant_left'],
    'reason' => [['reason' => 'max_call_duration'], 'max_call_duration'],
    'shutdown_reason wins' => [['shutdown_reason' => 'participant_left', 'reason' => 'other'], 'participant_left'],
    'neither' => [[], 'unknown'],
    'not a string' => [['shutdown_reason' => ['x'], 'reason' => 5], 'unknown'],
    'empty' => [['shutdown_reason' => ''], 'unknown'],
]);

it('maps the transcription being ready and keeps the rows it carries', function () {
    $rows = [['role' => 'user', 'content' => 'Hi', 'seconds_from_start' => 1.0]];

    $events = TavusEventMapper::map(tavusCallback('application.transcription_ready', ['transcript' => $rows]));

    expect($events[0]->kind)->toBe(ProviderEventKind::TranscriptReady)
        ->and($events[0]->payload['properties']['transcript'])->toBe($rows);
});

it('keeps what the provider sent for the receipt', function () {
    $payload = tavusCallback('system.shutdown', ['shutdown_reason' => 'participant_left']);

    expect(TavusEventMapper::map($payload)[0]->payload)->toBe($payload);
});

it('ignores every other event but still reports it', function (string $type) {
    $events = TavusEventMapper::map(tavusCallback($type));

    expect($events)->toHaveCount(1)->and($events[0]->kind)->toBe(ProviderEventKind::Ignored);
})->with([
    'application.recording_ready',
    'application.recording_copy_failed',
    'application.perception_analysis',
    'application.post_call_action_executed',
    'system.something_new',
]);

it('keys an event on conversation, type and timestamp', function () {
    $key = TavusEventMapper::map(tavusCallback('system.shutdown'))[0]->idempotencyKey;

    expect($key)->toBe(hash('sha256', 'c123|system.shutdown|2026-10-19T12:10:00.000000Z'))
        ->and(TavusEventMapper::map(tavusCallback('system.shutdown'))[0]->idempotencyKey)->toBe($key)
        ->and(TavusEventMapper::map(tavusCallback('system.shutdown', timestamp: '2026-10-19T12:10:01.000000Z'))[0]->idempotencyKey)->not->toBe($key)
        ->and(TavusEventMapper::map(tavusCallback('system.pal_joined'))[0]->idempotencyKey)->not->toBe($key);
});

it('stamps an event with no readable timestamp with the time it was received', function (mixed $timestamp) {
    $this->travelTo('2026-10-19 13:00:00');
    $payload = tavusCallback('system.pal_joined');
    $payload['timestamp'] = $timestamp;

    $event = TavusEventMapper::map($payload)[0];

    expect($event->occurredAt->format('Y-m-d\TH:i:s'))->toBe('2026-10-19T13:00:00');
})->with(['garbage' => 'not a time', 'missing' => null, 'number' => 12345]);

it('maps nothing from a body that names no conversation or no event', function (array $payload) {
    expect(TavusEventMapper::map($payload))->toBe([]);
})->with([
    'empty' => [[]],
    'no conversation' => [['event_type' => 'system.shutdown', 'timestamp' => 't']],
    'no event type' => [['conversation_id' => 'c123', 'timestamp' => 't']],
    'empty conversation' => [['conversation_id' => '', 'event_type' => 'system.shutdown']],
    'not strings' => [['conversation_id' => 7, 'event_type' => ['x']]],
]);

it('parses callbacks through the adapter', function () {
    $adapter = new TavusProvider;

    expect($adapter->ingestion())->toBe(Ingestion::Callback)
        ->and($adapter->parseCallback(tavusCallback('system.shutdown', ['reason' => 'timeout']))[0]->providerEndReason)->toBe('timeout')
        ->and($adapter->parseCallback([]))->toBe([]);
});

it('maps the callback fixture', function () {
    $payload = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Tavus/callback_shutdown.json'), true, flags: JSON_THROW_ON_ERROR);

    $event = TavusEventMapper::map($payload)[0];

    expect($event->kind)->toBe(ProviderEventKind::Ended)
        ->and($event->providerEndReason)->toBe('participant_left')
        ->and($event->providerRef)->toBe($payload['conversation_id']);
});

// Review rv14 R3-002 (same rule for Tavus): a time with no offset is UTC, whatever the application's timezone is.
it('reads a time that carries no offset as UTC, under any application timezone', function (string $timezone) {
    $original = date_default_timezone_get();
    date_default_timezone_set($timezone);

    try {
        $at = TavusEventMapper::time('2025-10-19T12:00:00');
    } finally {
        // A throw must not leave the changed timezone to the tests that follow in this process.
        date_default_timezone_set($original);
    }

    expect($at->getTimestamp())->toBe(1760875200);
})->with(['UTC', 'America/New_York', 'Asia/Tokyo']);
