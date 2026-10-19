<?php

use App\AvatarProviders\LiveAvatar\LiveAvatarProvider;
use App\Credentials\ProviderAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->provider = app(LiveAvatarProvider::class);
    $this->auth = new ProviderAuth('la-secret-key-123456', ProviderAuth::PLATFORM);
});

function laTeardownVerbs(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
}

it('stops the session with its documented body and then deletes the context', function () {
    Http::fake([
        'api.liveavatar.com/v1/sessions/stop' => Http::response(['code' => 1000]),
        'api.liveavatar.com/v1/contexts/ctx_1' => Http::response(['code' => 1000]),
    ]);

    $released = $this->provider->teardown('sess_1', 'ctx_1', $this->auth);

    expect($released)->toBeTrue()
        ->and(laTeardownVerbs())->toBe(['POST /v1/sessions/stop', 'DELETE /v1/contexts/ctx_1']);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.liveavatar.com/v1/sessions/stop'
        && $request->header('X-API-KEY') === ['la-secret-key-123456']
        && $request->data() === ['session_id' => 'sess_1', 'reason' => 'USER_CLOSED']);
});

it('never ends a session with DELETE /sessions/{id}, which LiveAvatar answers with a 405 and leaves running', function () {
    Http::fake(['*' => Http::response([])]);

    $this->provider->teardown('sess_1', 'ctx_1', $this->auth);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/sessions'));
});

it('counts a session that is already gone as released, and still removes the context', function () {
    Http::fake([
        'api.liveavatar.com/v1/sessions/stop' => Http::response(['message' => 'not found'], 404),
        'api.liveavatar.com/v1/contexts/ctx_1' => Http::response([]),
    ]);

    expect($this->provider->teardown('sess_1', 'ctx_1', $this->auth))->toBeTrue()
        ->and(laTeardownVerbs())->toBe(['POST /v1/sessions/stop', 'DELETE /v1/contexts/ctx_1']);
});

it('is safe to call twice', function () {
    Http::fake([
        'api.liveavatar.com/v1/sessions/stop' => Http::sequence()->push(['code' => 1000])->push(['message' => 'not found'], 404),
        'api.liveavatar.com/v1/contexts/ctx_1' => Http::sequence()->push([])->push([], 404),
    ]);

    expect($this->provider->teardown('sess_1', 'ctx_1', $this->auth))->toBeTrue()
        ->and($this->provider->teardown('sess_1', 'ctx_1', $this->auth))->toBeTrue();
});

it('reports false, throws nothing and keeps the context when the stop fails', function (Closure $answer, string $logged) {
    Http::fake(['api.liveavatar.com/v1/sessions/stop' => $answer]);
    $logs = captureLogs();

    $released = $this->provider->teardown('sess_1', 'ctx_1', $this->auth);

    // The session may still be using the context, so it must not be deleted from under it.
    expect($released)->toBeFalse()
        ->and(laTeardownVerbs())->toBe(['POST /v1/sessions/stop'])
        ->and(implode("\n", $logs->getArrayCopy()))->toContain($logged)->not->toContain('la-secret-key-123456')->not->toContain('body of the provider');
})->with([
    '500' => [fn () => Http::response(['message' => 'body of the provider la-secret-key-123456'], 500), '5xx'],
    '429' => [fn () => Http::response(['message' => 'body of the provider'], 429), '4xx'],
    '401' => [fn () => Http::response(['message' => 'body of the provider'], 401), '4xx'],
]);

it('reports false, without throwing, when the stop gets no answer', function () {
    Http::fake(['api.liveavatar.com/v1/sessions/stop' => fn () => throw new ConnectionException('timeout for la-secret-key-123456')]);
    $logs = captureLogs();

    expect($this->provider->teardown('sess_1', 'ctx_1', $this->auth))->toBeFalse()
        ->and(implode("\n", $logs->getArrayCopy()))->toContain('none')->not->toContain('la-secret-key-123456');
});

it('deletes no context when there is none to delete', function (?string $contextRef) {
    Http::fake(['api.liveavatar.com/v1/sessions/stop' => Http::response([])]);

    expect($this->provider->teardown('sess_1', $contextRef, $this->auth))->toBeTrue()
        ->and(laTeardownVerbs())->toBe(['POST /v1/sessions/stop']);
})->with(['null' => [null], 'empty' => ['']]);

it('reports the stop result even when the context delete fails, logging the status class only', function (Closure $answer, string $logged) {
    Http::fake([
        'api.liveavatar.com/v1/sessions/stop' => Http::response([]),
        'api.liveavatar.com/v1/contexts/ctx_1' => $answer,
    ]);
    $logs = captureLogs();

    expect($this->provider->teardown('sess_1', 'ctx_1', $this->auth))->toBeTrue()
        ->and(implode("\n", $logs->getArrayCopy()))->toContain($logged)->not->toContain('body of the provider');
})->with([
    'a 500' => [fn () => Http::response(['message' => 'body of the provider'], 500), '5xx'],
    'a timeout' => [fn () => throw new ConnectionException('timeout'), 'none'],
]);

it('treats a context that is already deleted as benign: no log line', function () {
    Http::fake([
        'api.liveavatar.com/v1/sessions/stop' => Http::response([]),
        'api.liveavatar.com/v1/contexts/ctx_1' => Http::response([], 404),
    ]);
    $logs = captureLogs();

    expect($this->provider->teardown('sess_1', 'ctx_1', $this->auth))->toBeTrue()
        ->and($logs->getArrayCopy())->toBe([]);
});

it('encodes the context ref into the path', function () {
    Http::fake(['*' => Http::response([])]);

    $this->provider->teardown('sess_1', 'ctx/../x', $this->auth);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://api.liveavatar.com/v1/contexts/ctx%2F..%2Fx');
});
