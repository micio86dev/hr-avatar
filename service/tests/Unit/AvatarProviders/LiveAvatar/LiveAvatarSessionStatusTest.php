<?php

use App\AvatarProviders\FailureClass;
use App\AvatarProviders\LiveAvatar\LiveAvatarProvider;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\SessionStatus;
use App\Credentials\ProviderAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->provider = app(LiveAvatarProvider::class);
    $this->auth = new ProviderAuth('la-secret-key-123456', ProviderAuth::PLATFORM);
});

/** @param  array<string, mixed>  $data */
function laSessionFake(array $data): void
{
    Http::fake(['api.liveavatar.com/v1/sessions/sess_1' => Http::response(['code' => 1000, 'data' => ['session_id' => 'sess_1', ...$data]])]);
}

it('reads the session resource and reports an active session as not ended', function () {
    laSessionFake(['end_at' => null, 'end_reason' => null]);

    $status = $this->provider->fetchSession('sess_1', $this->auth);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.liveavatar.com/v1/sessions/sess_1'
        && $request->header('X-API-KEY') === ['la-secret-key-123456']);
    expect($status)->toBeInstanceOf(SessionStatus::class)
        ->and([$status->ended, $status->endReason])->toBe([false, null]);
});

it('reports a session as ended when LiveAvatar says why or when, with the reason it gave', function (array $data, ?string $reason) {
    laSessionFake($data);

    $status = $this->provider->fetchSession('sess_1', $this->auth);

    expect([$status->ended, $status->endReason])->toBe([true, $reason]);
})->with([
    'a reason' => [['end_at' => null, 'end_reason' => 'IDLE_TIMEOUT'], 'IDLE_TIMEOUT'],
    'a reason and a time' => [['end_at' => '2026-10-19T12:00:00Z', 'end_reason' => 'USER_CLOSED'], 'USER_CLOSED'],
    'the maximum duration' => [['end_reason' => 'MAX_DURATION_REACHED'], 'MAX_DURATION_REACHED'],
    'a time and no reason' => [['end_at' => '2026-10-19T12:00:00Z', 'end_reason' => null], 'unknown'],
    'a time and an empty reason' => [['end_at' => '2026-10-19T12:00:00Z', 'end_reason' => ''], 'unknown'],
    'a reason that is not text' => [['end_reason' => 7], 'unknown'],
]);

it('reports a session without the end fields as not ended', function () {
    laSessionFake([]);

    expect($this->provider->fetchSession('sess_1', $this->auth)->ended)->toBeFalse();
});

it('raises a provider error, never an end, when LiveAvatar fails', function (Closure $answer, FailureClass $class) {
    Http::fake(['api.liveavatar.com/v1/sessions/sess_1' => $answer]);

    try {
        $this->provider->fetchSession('sess_1', $this->auth);
        $this->fail('expected a ProviderException');
    } catch (ProviderException $error) {
        expect($error->failureClass())->toBe($class)->and($error->getMessage())->not->toContain('body of the provider');
    }
})->with([
    '502' => [fn () => Http::response(['message' => 'body of the provider'], 502), FailureClass::Upstream],
    '500' => [fn () => Http::response([], 500), FailureClass::Upstream],
    '429' => [fn () => Http::response([], 429), FailureClass::Throttle],
    '404' => [fn () => Http::response(['message' => 'body of the provider'], 404), FailureClass::ClientError],
    'a timeout' => [fn () => throw new ConnectionException('timeout'), FailureClass::Upstream],
    'a 200 that is not a session' => [fn () => Http::response(['code' => 1000]), FailureClass::Upstream],
    'a 200 that is not JSON' => [fn () => Http::response('<html>', 200), FailureClass::Upstream],
]);

it('has no callbacks to parse: LiveAvatar sends none', function (array $payload) {
    expect($this->provider->parseCallback($payload))->toBe([]);
})->with([
    'empty' => [[]],
    'something that looks like an event' => [['event_type' => 'session.stopped', 'session_id' => 'sess_1']],
]);
