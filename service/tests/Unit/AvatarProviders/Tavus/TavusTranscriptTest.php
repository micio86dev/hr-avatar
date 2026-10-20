<?php

use App\AvatarProviders\FailureClass;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\Tavus\TavusProvider;
use App\AvatarProviders\TranscriptLine;
use App\AvatarProviders\TranscriptNotReady;
use App\AvatarProviders\TranscriptShapeError;
use App\Credentials\ProviderAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->auth = new ProviderAuth('tvs_live_SUPER_SECRET_KEY_99999', 'acc_1');
});

/**
 * @param  list<mixed>  $rows
 */
function tavusConversationWith(array $rows): array
{
    return ['status' => 'ended', 'events' => [
        ['event_type' => 'system.shutdown', 'properties' => ['shutdown_reason' => 'participant_left']],
        ['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => $rows]],
    ]];
}

/**
 * @return list<TranscriptLine>
 */
function tavusTranscript(array $conversation): array
{
    Http::fake(['*' => Http::response($conversation, 200)]);

    return (new TavusProvider)->fetchTranscript('c123', test()->auth);
}

it('reads the transcript off the one conversation, verbosely, with the key', function () {
    tavusTranscript(tavusConversationWith([['role' => 'user', 'content' => 'Hi']]));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://tavusapi.com/v2/conversations/c123?verbose=true'
        && $request->header('x-api-key') === ['tvs_live_SUPER_SECRET_KEY_99999']);
});

// Review rv12 R3-003: an empty transcription_ready event is not the transcript, so the search goes on to a later one.
it('reads the first transcription_ready event that carries rows', function () {
    $lines = tavusTranscript(['status' => 'ended', 'events' => [
        ['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => []]],
        ['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => [['role' => 'user', 'content' => 'Later']]]],
    ]]);

    expect(array_map(fn (TranscriptLine $l) => $l->text, $lines))->toBe(['Later']);
});

it('maps the roles, keeps provider order and leaves the system entry out', function () {
    $lines = tavusTranscript(tavusConversationWith([
        ['role' => 'system', 'content' => 'You are a friendly interviewer.'],
        ['role' => 'assistant', 'content' => 'Hello!'],
        ['role' => 'user', 'content' => 'Hi there.'],
        ['role' => 'replica', 'content' => 'How are you?'],
    ]));

    expect(array_map(fn (TranscriptLine $l) => [$l->role, $l->text], $lines))->toBe([
        ['avatar', 'Hello!'],
        ['user', 'Hi there.'],
        ['avatar', 'How are you?'],
    ]);
});

it('carries the time and the offset of a line when Tavus gives them, and null when it does not', function () {
    $lines = tavusTranscript(tavusConversationWith([
        ['role' => 'assistant', 'content' => 'Hello!', 'timestamp' => '2026-10-19T12:00:07.000000Z', 'seconds_from_start' => 2.5],
        ['role' => 'user', 'content' => 'Hi.', 'timestamp' => 'not a time', 'seconds_from_start' => 'soon'],
        ['role' => 'user', 'content' => 'Bye.'],
    ]));

    expect($lines[0]->occurredAt?->format('Y-m-d\TH:i:s'))->toBe('2026-10-19T12:00:07')
        ->and($lines[0]->offsetSeconds)->toBe(2.5)
        ->and($lines[1]->occurredAt)->toBeNull()->and($lines[1]->offsetSeconds)->toBeNull()
        ->and($lines[2]->occurredAt)->toBeNull()->and($lines[2]->offsetSeconds)->toBeNull();
});

it('reads an integer offset as a float', function () {
    expect(tavusTranscript(tavusConversationWith([['role' => 'user', 'content' => 'Hi', 'seconds_from_start' => 3]]))[0]->offsetSeconds)->toBe(3.0);
});

it('returns no lines for a conversation whose only entry is the system prompt', function () {
    expect(tavusTranscript(tavusConversationWith([['role' => 'system', 'content' => 'You are a friendly interviewer.']])))->toBe([]);
});

it('refuses a row it cannot read instead of dropping or coercing it', function (array $row) {
    expect(fn () => tavusTranscript(tavusConversationWith([['role' => 'user', 'content' => 'fine'], $row])))
        ->toThrow(TranscriptShapeError::class);
})->with([
    'unknown role' => [['role' => 'moderator', 'content' => 'Hello']],
    'missing role' => [['content' => 'Hello']],
    'role not a string' => [['role' => ['user'], 'content' => 'Hello']],
    'missing text' => [['role' => 'user']],
    'text not a string' => [['role' => 'user', 'content' => 42]],
]);

it('refuses a transcript whose rows are not objects', function () {
    expect(fn () => tavusTranscript(tavusConversationWith(['just a string'])))->toThrow(TranscriptShapeError::class);
});

it('does not quote the provider text in the shape error', function () {
    try {
        tavusTranscript(tavusConversationWith([['role' => 'moderator', 'content' => 'a secret line']]));
        $this->fail('Expected a TranscriptShapeError');
    } catch (TranscriptShapeError $e) {
        expect($e->getMessage())->not->toContain('moderator')->not->toContain('secret');
    }
});

it('is not ready until Tavus has delivered the transcript', function (array $conversation) {
    expect(fn () => tavusTranscript($conversation))->toThrow(TranscriptNotReady::class);
})->with([
    'still active' => [['status' => 'active']],
    'ended, no transcript event yet' => [['status' => 'ended', 'events' => [['event_type' => 'system.shutdown', 'properties' => []]]]],
    'event with an empty transcript' => [tavusConversationWith([])],
    'event without a transcript list' => [['events' => [['event_type' => 'application.transcription_ready', 'properties' => []]]]],
    'no events at all' => [[]],
]);

it('raises a provider error for a failed call or a response that is not JSON', function () {
    Http::fake(['*' => Http::response('nope', 502)]);
    try {
        (new TavusProvider)->fetchTranscript('c123', $this->auth);
        $this->fail('Expected a ProviderException');
    } catch (ProviderException $e) {
        expect($e->failureClass())->toBe(FailureClass::Upstream)->and($e->getMessage())->toBe('Provider request failed (status 502)');
    }

    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 7')]);
    expect(fn () => (new TavusProvider)->fetchTranscript('c123', $this->auth))->toThrow(ProviderException::class, '(no response)');

    Http::fake(['*' => Http::response('<html></html>', 200)]);
    expect(fn () => (new TavusProvider)->fetchTranscript('c123', $this->auth))->toThrow(ProviderException::class, '(no response)');
});

it('reads the conversation fixture into the two spoken lines', function () {
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Tavus/conversation_verbose_response.json'), true, flags: JSON_THROW_ON_ERROR);

    $lines = tavusTranscript($fixture);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->role)->toBe('avatar')->and($lines[0]->text)->toBe('Hello, tell me about yourself.')->and($lines[0]->offsetSeconds)->toBe(2.5)
        ->and($lines[1]->role)->toBe('user')->and($lines[1]->text)->toBe('I am a software engineer.');
});
