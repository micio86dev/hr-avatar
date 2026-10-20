<?php

use App\AvatarProviders\FailureClass;
use App\AvatarProviders\LiveAvatar\LiveAvatarProvider;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\TranscriptLine;
use App\AvatarProviders\TranscriptNotReady;
use App\AvatarProviders\TranscriptShapeError;
use App\Credentials\ProviderAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->provider = app(LiveAvatarProvider::class);
    $this->auth = new ProviderAuth('la-secret-key-123456', ProviderAuth::PLATFORM);
});

/** @param  list<array<string, mixed>>  $rows */
function laTranscriptFake(array $rows, bool $active = false): void
{
    Http::fake(['api.liveavatar.com/v1/sessions/sess_1/transcript' => Http::response([
        'code' => 1000,
        'data' => ['session_id' => 'sess_1', 'session_active' => $active, 'next_timestamp' => null, 'transcript_data' => $rows],
    ])]);
}

function laRow(string $role, string $text, mixed $absolute = 1760875200000, mixed $relative = 1500): array
{
    return ['role' => $role, 'transcript' => $text, 'absolute_timestamp' => $absolute, 'relative_timestamp' => $relative];
}

it('reads the transcript of the session and maps its rows to lines, in provider order', function () {
    laTranscriptFake([laRow('avatar', 'Ciao, come stai?'), laRow('user', 'Bene, grazie.', 1760875205000, 6000)]);

    $lines = $this->provider->fetchTranscript('sess_1', $this->auth);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.liveavatar.com/v1/sessions/sess_1/transcript'
        && $request->header('X-API-KEY') === ['la-secret-key-123456']);
    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toBeInstanceOf(TranscriptLine::class)
        ->and([$lines[0]->role, $lines[0]->text, $lines[0]->offsetSeconds])->toBe([TranscriptLine::AVATAR, 'Ciao, come stai?', 1.5])
        ->and($lines[0]->occurredAt->format('Y-m-d\TH:i:s\Z'))->toBe('2025-10-19T12:00:00Z')
        ->and([$lines[1]->role, $lines[1]->text, $lines[1]->offsetSeconds])->toBe([TranscriptLine::USER, 'Bene, grazie.', 6.0])
        ->and($lines[1]->occurredAt->format('Y-m-d\TH:i:s\Z'))->toBe('2025-10-19T12:00:05Z');
});

it('returns an empty list for a finished session that said nothing', function () {
    laTranscriptFake([]);

    expect($this->provider->fetchTranscript('sess_1', $this->auth))->toBe([]);
});

it('reads an absolute timestamp whether it is epoch milliseconds, epoch seconds or an ISO string', function (mixed $absolute) {
    laTranscriptFake([laRow('user', 'x', $absolute)]);

    expect($this->provider->fetchTranscript('sess_1', $this->auth)[0]->occurredAt->format('Y-m-d\TH:i:s\Z'))->toBe('2025-10-19T12:00:00Z');
})->with([
    'milliseconds' => [1760875200000],
    'seconds' => [1760875200],
    'numeric string' => ['1760875200000'],
    'ISO 8601' => ['2025-10-19T12:00:00Z'],
]);

it('leaves a timestamp it cannot read as null, instead of inventing one', function (mixed $absolute, mixed $relative) {
    laTranscriptFake([laRow('user', 'x', $absolute, $relative)]);

    $line = $this->provider->fetchTranscript('sess_1', $this->auth)[0];

    expect($line->occurredAt)->toBeNull()->and($line->offsetSeconds)->toBeNull();
})->with([
    'missing' => [null, null],
    'not a time' => ['yesterday-ish', 'soon'],
    'a list' => [['x'], ['y']],
]);

it('passes user and avatar through, and reads the assistant synonyms as the avatar', function (string $role, string $expected) {
    laTranscriptFake([laRow($role, 'x')]);

    expect($this->provider->fetchTranscript('sess_1', $this->auth)[0]->role)->toBe($expected);
})->with([
    'user' => ['user', TranscriptLine::USER],
    'avatar' => ['avatar', TranscriptLine::AVATAR],
    'assistant' => ['assistant', TranscriptLine::AVATAR],
    'agent' => ['agent', TranscriptLine::AVATAR],
]);

it('is not ready while the session is still active, even if rows are already there', function () {
    laTranscriptFake([laRow('user', 'x')], active: true);

    expect(fn () => $this->provider->fetchTranscript('sess_1', $this->auth))->toThrow(TranscriptNotReady::class);
});

it('raises a shape error for a row that is not what the contract says, never dropping or coercing it', function (mixed $row) {
    laTranscriptFake([laRow('user', 'fine'), $row]);

    expect(fn () => $this->provider->fetchTranscript('sess_1', $this->auth))->toThrow(TranscriptShapeError::class);
})->with([
    'an unknown role' => [laRow('moderator', 'secret-text-of-the-row')],
    'a candidate role of another vocabulary' => [laRow('candidate', 'x')],
    'a missing role' => [['transcript' => 'x']],
    'a role that is not a string' => [['role' => 3, 'transcript' => 'x']],
    'a missing text' => [['role' => 'user']],
    'a text that is not a string' => [['role' => 'user', 'transcript' => ['x']]],
    'a row that is not an object' => ['just a string'],
]);

it('raises a shape error when the answer has no list of transcript rows', function (array $body) {
    Http::fake(['api.liveavatar.com/v1/sessions/sess_1/transcript' => Http::response($body)]);

    expect(fn () => $this->provider->fetchTranscript('sess_1', $this->auth))->toThrow(TranscriptShapeError::class);
})->with([
    'no data' => [['code' => 1000]],
    'data without transcript_data' => [['data' => ['session_active' => false]]],
    'transcript_data at the wrong level' => [['transcript_data' => []]],
    'transcript_data that is not a list' => [['data' => ['transcript_data' => 'none']]],
    'transcript_data that is an object' => [['data' => ['transcript_data' => ['a' => []]]]],
]);

it('lets none of the provider\'s text into a shape error', function () {
    laTranscriptFake([laRow('moderator', 'secret-text-of-the-row')]);

    try {
        $this->provider->fetchTranscript('sess_1', $this->auth);
        $this->fail('expected a TranscriptShapeError');
    } catch (TranscriptShapeError $error) {
        expect($error->getMessage())->not->toContain('secret-text-of-the-row')->not->toContain('moderator');
    }
});

it('raises a provider error, not an empty transcript, when LiveAvatar fails', function (Closure $answer, FailureClass $class) {
    Http::fake(['api.liveavatar.com/v1/sessions/sess_1/transcript' => $answer]);

    try {
        $this->provider->fetchTranscript('sess_1', $this->auth);
        $this->fail('expected a ProviderException');
    } catch (ProviderException $error) {
        expect($error->failureClass())->toBe($class)->and($error->getMessage())->not->toContain('body of the provider');
    }
})->with([
    '404' => [fn () => Http::response(['message' => 'body of the provider'], 404), FailureClass::ClientError],
    '429' => [fn () => Http::response([], 429), FailureClass::Throttle],
    '502' => [fn () => Http::response(['message' => 'body of the provider'], 502), FailureClass::Upstream],
    'a timeout' => [fn () => throw new ConnectionException('timeout'), FailureClass::Upstream],
]);

// Review rv14 R3-002: a time with no offset is read as UTC, whatever the application's timezone is.
it('reads an ISO time that carries no offset as UTC, under any application timezone', function (string $timezone) {
    config(['app.timezone' => $timezone]);
    date_default_timezone_set($timezone);
    laTranscriptFake([laRow('user', 'x', '2025-10-19T12:00:00')]);

    $at = $this->provider->fetchTranscript('sess_1', $this->auth)[0]->occurredAt;

    date_default_timezone_set('UTC');
    expect($at->getTimestamp())->toBe(1760875200);
})->with(['UTC', 'America/New_York', 'Asia/Tokyo']);

it('keeps the offset an ISO time carries', function () {
    laTranscriptFake([laRow('user', 'x', '2025-10-19T14:00:00+02:00')]);

    expect($this->provider->fetchTranscript('sess_1', $this->auth)[0]->occurredAt->getTimestamp())->toBe(1760875200);
});

// Review rv14 R3-001: how the next page is asked for is undocumented, so only the first is read; that is said in the log.
it('says in the log, without any of the transcript, that a further page was not read', function () {
    $logs = captureLogs();
    Http::fake(['api.liveavatar.com/v1/sessions/sess_1/transcript' => Http::response([
        'data' => ['session_active' => false, 'next_timestamp' => 1760875300000, 'transcript_data' => [laRow('user', 'PRIVATE-WORDS')]],
    ])]);

    $lines = $this->provider->fetchTranscript('sess_1', $this->auth);

    $everything = implode("\n", $logs->getArrayCopy());
    expect($lines)->toHaveCount(1)->and($everything)->toContain('LiveAvatar transcript has a further page that was not read')->and($everything)->not->toContain('PRIVATE-WORDS');
});

it('logs nothing when the transcript has no further page', function () {
    $logs = captureLogs();
    laTranscriptFake([laRow('user', 'x')]);

    $this->provider->fetchTranscript('sess_1', $this->auth);

    expect($logs->getArrayCopy())->toBe([]);
});
