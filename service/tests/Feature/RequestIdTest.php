<?php

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/v1/_probe', fn () => response()->json(['ok' => true]))->name('probe.show');
});

it('echoes a supplied request id', function () {
    $this->getJson('/v1/_probe', ['X-Request-Id' => 'abc-123'])
        ->assertOk()
        ->assertHeader('X-Request-Id', 'abc-123');
});

it('replaces an invalid request id with a newly generated one', function (string $supplied) {
    $response = $this->getJson('/v1/_probe', ['X-Request-Id' => $supplied])->assertOk();

    expect($response->headers->get('X-Request-Id'))
        ->not->toBe($supplied)
        ->toMatch('/^[A-Za-z0-9._-]{1,64}$/');
})->with([
    'spaces' => ['has spaces in it'],
    'too long' => [str_repeat('a', 65)],
    'forbidden characters' => ['abc;drop'],
]);

it('accepts a request id of exactly 64 characters', function () {
    $id = str_repeat('a', 64);

    $this->getJson('/v1/_probe', ['X-Request-Id' => $id])->assertHeader('X-Request-Id', $id);
});

it('mints a different id for each request that supplies none', function () {
    $first = $this->getJson('/v1/_probe')->headers->get('X-Request-Id');
    $second = $this->getJson('/v1/_probe')->headers->get('X-Request-Id');

    expect($first)->toMatch('/^[A-Za-z0-9._-]{1,64}$/')->not->toBe($second);
});

it('puts the request id and the route name, never the path, in the log context', function () {
    $this->getJson('/v1/_probe', ['X-Request-Id' => 'ctx-1'])->assertOk();

    $context = Log::sharedContext();

    expect($context)->toMatchArray(['request_id' => 'ctx-1', 'route' => 'probe.show']);
    expect(json_encode($context))->not->toContain('_probe"');
});

it('rejects a request id with a trailing newline', function () {
    $request = Request::create('/v1/_probe', server: ['HTTP_X_REQUEST_ID' => "abc-123\n"]);

    expect(AssignRequestId::for($request))->not->toBe("abc-123\n")->toMatch('/^[A-Za-z0-9._-]{1,64}$/D');
});
