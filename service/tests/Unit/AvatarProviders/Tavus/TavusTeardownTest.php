<?php

use App\AvatarProviders\Tavus\TavusProvider;
use App\Credentials\ProviderAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const TAVUS_END_KEY = 'tvs_live_SUPER_SECRET_KEY_99999';

beforeEach(function () {
    $this->auth = new ProviderAuth(TAVUS_END_KEY, 'acc_1');
    $this->logs = captureLogs();
});

function tavusTeardown(string $ref = 'c123', ?string $contextRef = null): bool
{
    return (new TavusProvider)->teardown($ref, $contextRef, test()->auth);
}

it('ends the conversation with one authenticated POST and reports it released', function () {
    Http::fake(['*' => Http::response([], 200)]);

    expect(tavusTeardown('c123'))->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://tavusapi.com/v2/conversations/c123/end'
        && $request->header('x-api-key') === [TAVUS_END_KEY]);
});

it('counts a conversation Tavus no longer knows as released', function () {
    Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

    expect(tavusTeardown())->toBeTrue();
});

it('reports a refusal or a failure as not released, without throwing', function (int $status) {
    Http::fake(['*' => Http::response(['message' => 'echoed '.TAVUS_END_KEY], $status)]);

    expect(tavusTeardown())->toBeFalse();
})->with([400, 401, 422, 429, 500, 503]);

it('reports a transport failure as not released, without throwing', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28 while sending '.TAVUS_END_KEY)]);

    expect(tavusTeardown())->toBeFalse();
});

it('logs the status class of a failure and nothing else about it', function () {
    Http::fake(['*' => Http::response(['message' => 'echoed '.TAVUS_END_KEY], 503)]);

    tavusTeardown('c123');

    $logged = $this->logs->getArrayCopy();
    expect($logged)->toHaveCount(1)
        ->and($logged[0])->toContain('5xx')
        ->not->toContain(TAVUS_END_KEY)->not->toContain('echoed');
});

it('logs a transport failure without its message', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28 while sending '.TAVUS_END_KEY)]);

    tavusTeardown();

    $logged = implode("\n", $this->logs->getArrayCopy());
    expect($logged)->toContain('none')->not->toContain(TAVUS_END_KEY)->not->toContain('cURL');
});

it('is safe to call twice and has no context to remove', function () {
    Http::fake(['*' => Http::sequence()->push([], 200)->push(['message' => 'gone'], 404)]);

    expect(tavusTeardown('c123', 'ctx-ignored'))->toBeTrue()
        ->and(tavusTeardown('c123', 'ctx-ignored'))->toBeTrue();

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' || str_contains($request->url(), 'ctx-ignored'));
});
