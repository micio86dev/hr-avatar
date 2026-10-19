<?php

use App\AvatarProviders\LiveAvatar\LiveAvatarProvider;
use App\AvatarProviders\Mock\MockProvider;
use App\AvatarProviders\Tavus\TavusProvider;
use App\AvatarProviders\TranscriptLine;
use App\AvatarProviders\TranscriptShapeError;

/** @param  list<mixed>  $rows */
function tavusTranscriptCallback(array $rows): array
{
    return ['conversation_id' => 'c123', 'event_type' => 'application.transcription_ready', 'timestamp' => '2026-10-19T12:00:00Z', 'properties' => ['transcript' => $rows]];
}

it('reads the rows a transcription_ready callback carries the way a fetched transcript is read', function () {
    $lines = (new TavusProvider)->callbackTranscript(tavusTranscriptCallback([
        ['role' => 'system', 'content' => 'You are a friendly interviewer.'],
        ['role' => 'assistant', 'content' => 'Hello!', 'timestamp' => '2026-10-19T12:00:01Z', 'seconds_from_start' => 1.5],
        ['role' => 'replica', 'content' => 'Welcome.'],
        ['role' => 'user', 'content' => 'Hi'],
    ]));

    expect(array_map(fn (TranscriptLine $line) => [$line->role, $line->text], $lines))->toBe([['avatar', 'Hello!'], ['avatar', 'Welcome.'], ['user', 'Hi']])
        ->and($lines[0]->occurredAt?->format('c'))->toBe('2026-10-19T12:00:01+00:00')
        ->and($lines[0]->offsetSeconds)->toBe(1.5)
        ->and($lines[2]->occurredAt)->toBeNull();
});

it('reads no lines from a callback that carries no transcript', function (array $payload) {
    expect((new TavusProvider)->callbackTranscript($payload))->toBe([]);
})->with([
    'no properties' => [['conversation_id' => 'c123', 'event_type' => 'application.transcription_ready']],
    'no transcript' => [['properties' => ['other' => 1]]],
    'an empty transcript' => [['properties' => ['transcript' => []]]],
    'a transcript that is not a list' => [['properties' => ['transcript' => 'text']]],
]);

it('raises a shape error for a row it cannot read, as a fetched transcript does', function (array $row) {
    expect(fn () => (new TavusProvider)->callbackTranscript(tavusTranscriptCallback([$row])))->toThrow(TranscriptShapeError::class);
})->with([
    'an unknown role' => [['role' => 'narrator', 'content' => 'Hi']],
    'no text' => [['role' => 'user']],
]);

it('is empty for an adapter that has no callbacks', function (string $adapter) {
    expect(app($adapter)->callbackTranscript(tavusTranscriptCallback([['role' => 'user', 'content' => 'Hi']])))->toBe([]);
})->with([MockProvider::class, LiveAvatarProvider::class]);
