<?php

use App\AvatarProviders\FailureClass;
use App\AvatarProviders\LiveAvatar\LiveAvatarApi;
use App\AvatarProviders\ProviderException;
use App\Credentials\ProviderAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->api = new LiveAvatarApi;
    $this->auth = new ProviderAuth('la-secret-key-123456', ProviderAuth::PLATFORM);
});

it('sends each verb to the LiveAvatar v1 host with the key in X-API-KEY and the body as JSON', function (string $method, string $path, array $body) {
    Http::fake(['api.liveavatar.com/*' => Http::response(['data' => []])]);

    $this->api->call($this->auth, $method, $path, $body);

    Http::assertSent(fn (Request $request) => $request->method() === $method
        && $request->url() === "https://api.liveavatar.com/v1{$path}"
        && $request->header('X-API-KEY') === ['la-secret-key-123456']
        && $request->data() === $body);
})->with([
    'POST with a body' => ['POST', '/contexts', ['name' => 'a']],
    'PATCH with a body' => ['PATCH', '/llm-configurations/cfg_1', ['model_name' => 'm']],
    'GET' => ['GET', '/sessions/s1', []],
    'DELETE' => ['DELETE', '/contexts/ctx_1', []],
]);

it('hands back a non-2xx answer untouched, so the caller decides what a 404 means', function () {
    Http::fake(['api.liveavatar.com/*' => Http::response(['message' => 'gone'], 404)]);

    $response = $this->api->call($this->auth, 'DELETE', '/contexts/ctx_1');

    expect($response->status())->toBe(404);
});

it('turns a transport failure into a provider error that carries nothing of the failure', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28 for la-secret-key-123456'));

    try {
        $this->api->call($this->auth, 'GET', '/sessions/s1');
        $this->fail('expected a ProviderException');
    } catch (ProviderException $error) {
        expect($error->getMessage())->toBe('Provider request failed (no response)')
            ->and($error->status())->toBeNull()
            ->and($error->failureClass())->toBe(FailureClass::Upstream)
            ->and($error->getPrevious())->toBeNull()
            ->and($error->getMessage().$error->getTraceAsString())->not->toContain('la-secret-key-123456');
    }
});

it('does not hide a missing fake: a stray request still fails the test', function () {
    expect(fn () => $this->api->call($this->auth, 'GET', '/sessions/s1'))->toThrow(RuntimeException::class, 'without a matching fake');
});

it('lets a 2xx answer through orFail and classifies every other status', function (int $status, ?FailureClass $class) {
    Http::fake(['api.liveavatar.com/*' => Http::response(['message' => 'body with la-secret-key-123456'], $status)]);
    $response = $this->api->call($this->auth, 'GET', '/sessions/s1');

    if ($class === null) {
        expect($this->api->orFail($response))->toBe($response);

        return;
    }

    try {
        $this->api->orFail($response);
        $this->fail('expected a ProviderException');
    } catch (ProviderException $error) {
        expect($error->failureClass())->toBe($class)
            ->and($error->status())->toBe($status)
            ->and($error->getMessage())->toBe("Provider request failed (status {$status})");
    }
})->with([
    '200' => [200, null],
    '204' => [204, null],
    '422 is the provider refusing our request' => [422, FailureClass::ClientError],
    '429 is a throttle' => [429, FailureClass::Throttle],
    '503 is the provider failing' => [503, FailureClass::Upstream],
]);

it('reads a non-empty string at a path of the answer, and nothing else', function (mixed $value, ?string $expected) {
    Http::fake(['api.liveavatar.com/*' => Http::response(['data' => ['id' => $value]])]);
    $response = $this->api->call($this->auth, 'POST', '/contexts');

    expect($this->api->stringAt($response, 'data.id'))->toBe($expected);
})->with([
    'a string' => ['ctx_1', 'ctx_1'],
    'an empty string' => ['', null],
    'null' => [null, null],
    'a number' => [12, null],
    'a list' => [['x'], null],
]);

it('reads null when the path is absent', function () {
    Http::fake(['api.liveavatar.com/*' => Http::response(['id' => 'top-level-is-not-data-id'])]);

    expect($this->api->stringAt($this->api->call($this->auth, 'POST', '/contexts'), 'data.id'))->toBeNull();
});
