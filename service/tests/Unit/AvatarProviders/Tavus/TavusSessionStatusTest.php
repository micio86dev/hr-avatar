<?php

use App\AvatarProviders\FailureClass;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\Tavus\TavusProvider;
use App\Credentials\ProviderAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->auth = new ProviderAuth('tvs_live_SUPER_SECRET_KEY_99999', 'acc_1');
});

function tavusSession(array $body)
{
    Http::fake(['*' => Http::response($body, 200)]);

    return (new TavusProvider)->fetchSession('c123', test()->auth);
}

it('reads the one conversation, verbosely, with the key', function () {
    tavusSession(['status' => 'active']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://tavusapi.com/v2/conversations/c123?verbose=true'
        && $request->header('x-api-key') === ['tvs_live_SUPER_SECRET_KEY_99999']);
});

it('is not ended while the conversation is active', function () {
    $status = tavusSession(['status' => 'active']);

    expect($status->ended)->toBeFalse()->and($status->endReason)->toBeNull();
});

it('is ended only for the terminal status Tavus documents', function () {
    expect(tavusSession(['status' => 'ended'])->ended)->toBeTrue();
});

// Ruling 53: an allow-list. Tavus documents `active` and `ended` only, so any other word is not read as an end.
it('does not read an undocumented status as an end, and logs the status value only', function (string $state) {
    $logs = captureLogs();

    $status = tavusSession(['status' => $state, 'conversation_id' => 'c123', 'api_key' => 'tvs_live_SUPER_SECRET_KEY_99999']);

    expect($status->ended)->toBeFalse()->and($status->endReason)->toBeNull();
    $lines = $logs->getArrayCopy();
    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toBe('Tavus returned an unrecognised conversation status {"status":"'.$state.'"}');
})->with(['completed', 'failed', 'paused', 'Ended']);

it('keeps an unrecognised status from carrying anything but a short value into the log', function () {
    $logs = captureLogs();

    tavusSession(['status' => str_repeat('x', 500)]);

    expect($logs->getArrayCopy()[0])->toBe('Tavus returned an unrecognised conversation status {"status":"'.str_repeat('x', 32).'"}');
});

it('logs nothing for the two documented statuses', function (string $state) {
    $logs = captureLogs();

    tavusSession(['status' => $state]);

    expect($logs->getArrayCopy())->toBe([]);
})->with(['active', 'ended']);

it('carries the shutdown reason Tavus gives, wherever it gives it', function (array $body, ?string $reason) {
    expect(tavusSession($body + ['status' => 'ended'])->endReason)->toBe($reason);
})->with([
    'top level' => [['shutdown_reason' => 'max_call_duration'], 'max_call_duration'],
    'shutdown event' => [['events' => [['event_type' => 'system.shutdown', 'properties' => ['shutdown_reason' => 'participant_left']]]], 'participant_left'],
    'shutdown event, legacy key' => [['events' => [['event_type' => 'system.shutdown', 'properties' => ['reason' => 'timeout']]]], 'timeout'],
    'none given' => [['events' => [['event_type' => 'system.replica_joined', 'properties' => []]]], null],
    'no events' => [[], null],
]);

it('treats a response without a usable status as a provider error and never as an end', function (array $body) {
    expect(fn () => tavusSession($body))->toThrow(ProviderException::class, 'Provider request failed (no response)');
})->with([
    'no status' => [[]],
    'not a string' => [['status' => ['ended']]],
    'empty status' => [['status' => '']],
]);

it('raises a provider error for a failed call, never an end', function (int $status, FailureClass $class) {
    Http::fake(['*' => Http::response(['message' => 'echoed secret'], $status)]);

    try {
        (new TavusProvider)->fetchSession('c123', $this->auth);
        $this->fail('Expected a ProviderException');
    } catch (ProviderException $e) {
        expect($e->failureClass())->toBe($class)
            ->and($e->getMessage())->toBe("Provider request failed (status {$status})");
    }
})->with([
    'not found' => [404, FailureClass::ClientError],
    'rate limited' => [429, FailureClass::Throttle],
    'bad gateway' => [502, FailureClass::Upstream],
]);

it('raises a provider error when Tavus cannot be reached', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 7')]);

    expect(fn () => (new TavusProvider)->fetchSession('c123', $this->auth))
        ->toThrow(ProviderException::class, 'Provider request failed (no response)');
});

it('reads a body that is not JSON as no response', function () {
    Http::fake(['*' => Http::response('<html>bad gateway</html>', 200)]);

    expect(fn () => (new TavusProvider)->fetchSession('c123', $this->auth))
        ->toThrow(ProviderException::class, 'Provider request failed (no response)');
});

it('reads the conversation fixture as ended with its shutdown reason', function () {
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/Tavus/conversation_verbose_response.json'), true, flags: JSON_THROW_ON_ERROR);

    $status = tavusSession($fixture);

    expect($status->ended)->toBeTrue()->and($status->endReason)->toBe('participant_left');
});
