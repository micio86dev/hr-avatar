<?php

use App\AvatarProviders\ProviderException;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

const PROBLEM_MEMBERS = ['type', 'title', 'status', 'detail', 'code', 'request_id'];

function expectProblem($response, int $status, string $code): void
{
    $response->assertStatus($status);
    expect($response->headers->get('Content-Type'))->toBe('application/problem+json');
    expect($response->json())->toHaveKeys(PROBLEM_MEMBERS)->and($response->json('code'))->toBe($code);
    expect($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
}

it('answers validation failures with field errors', function () {
    Route::post('/v1/_probe', fn () => request()->validate(['name' => 'required', 'age' => 'integer']));

    $response = $this->postJson('/v1/_probe', ['age' => 'abc']);

    expectProblem($response, 422, 'validation_failed');
    expect($response->json('errors'))->toHaveKeys(['name', 'age'])
        ->and($response->json('errors.name'))->toBeArray()->not->toBeEmpty()
        ->and($response->json('detail'))->toBeString()->not->toBeEmpty();
});

it('keeps an unhandled exception opaque, even with debug on', function () {
    config()->set('app.debug', true);
    Route::get('/v1/_probe', fn () => throw new RuntimeException('leaked-secret-value'));

    $response = $this->getJson('/v1/_probe');

    expectProblem($response, 500, 'internal_error');
    expect($response->getContent())
        ->not->toContain('leaked-secret-value')
        ->not->toContain('trace')
        ->not->toContain('RuntimeException')
        ->not->toContain('.php');
});

it('does not localize the code', function () {
    $plain = $this->getJson('/v1/does-not-exist');
    $italian = $this->getJson('/v1/does-not-exist', ['Accept-Language' => 'it']);

    expect($plain->json('code'))->toBe('not_found')->and($italian->json('code'))->toBe('not_found');
});

it('answers an unknown path with Problem+JSON, never HTML', function (string $path, array $headers) {
    expectProblem($this->get($path, $headers), 404, 'not_found');
})->with([
    'v1 path' => ['/v1/does-not-exist', ['Accept' => 'application/json']],
    'v1 path asking for HTML' => ['/v1/does-not-exist', ['Accept' => 'text/html']],
    'outside v1' => ['/anything', ['Accept' => 'text/html']],
]);

it('answers an unsupported method with 405 and an Allow header', function () {
    Route::get('/v1/_probe', fn () => ['ok' => true]);

    $response = $this->deleteJson('/v1/_probe');

    expectProblem($response, 405, 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('GET');
});

it('maps exceptions to catalogue codes', function (Closure $thrower, int $status, string $code) {
    Route::get('/v1/_probe', fn () => $thrower());

    expectProblem($this->getJson('/v1/_probe'), $status, $code);
})->with([
    'authentication' => [fn () => throw new AuthenticationException, 401, 'unauthenticated'],
    'model not found' => [fn () => throw new ModelNotFoundException, 404, 'not_found'],
    'body too large' => [fn () => throw new PostTooLargeException, 413, 'payload_too_large'],
    'service unavailable' => [fn () => throw new ServiceUnavailableHttpException(30), 503, 'service_unavailable'],
]);

// The catalogue is closed (service-foundation spec): a status with no code of its own is answered with the closest code
// that tells a caller the right thing to do. A timeout is a try-again (503), an upstream failure is the provider's (502).
it('answers a status with no code of its own with the closest catalogue code', function (int $thrown, int $status, string $code) {
    Route::get('/v1/_probe', fn () => throw new HttpException($thrown));

    expectProblem($this->getJson('/v1/_probe'), $status, $code);
})->with([
    '408 request timeout is retryable' => [408, 503, 'service_unavailable'],
    '502 bad gateway is the upstream failing' => [502, 502, 'provider_error'],
    '504 gateway timeout is the upstream failing' => [504, 502, 'provider_error'],
    '415 stays a request that failed validation' => [415, 422, 'validation_failed'],
    '501 stays internal' => [501, 500, 'internal_error'],
    '429 stays rate limited' => [429, 429, 'rate_limited'],
]);

it('answers a throttled request with 429 and Retry-After', function () {
    Route::get('/v1/_probe', fn () => ['ok' => true])->middleware('throttle:1,1');

    $this->getJson('/v1/_probe')->assertOk();
    $response = $this->getJson('/v1/_probe');

    expectProblem($response, 429, 'rate_limited');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('renders an ApiProblem with its own status, code, detail, errors and headers', function () {
    Route::get('/v1/_probe', fn () => throw new ApiProblem(
        ErrorCode::ConfigInUse,
        'Referenced by an open conversation.',
        errors: ['id' => ['Still in use.']],
        headers: ['X-Hint' => 'end-conversations'],
        extra: ['checks' => ['database' => 'ok']],
    ));

    $response = $this->getJson('/v1/_probe');

    expectProblem($response, 409, 'config_in_use');
    expect($response->json('detail'))->toBe('Referenced by an open conversation.')
        ->and($response->json('errors'))->toBe(['id' => ['Still in use.']])
        ->and($response->json('checks'))->toBe(['database' => 'ok'])
        ->and($response->headers->get('X-Hint'))->toBe('end-conversations');
});

it('exposes no unversioned API surface', function () {
    Route::get('/v1/_probe', fn () => ['ok' => true]);

    $paths = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => '/'.ltrim($route->uri(), '/'));

    expect($paths)->not->toBeEmpty();
    $paths->each(fn (string $path) => expect($path)->toStartWith('/v1/'));
    expectProblem($this->getJson('/health'), 404, 'not_found');
    expectProblem($this->getJson('/api/health'), 404, 'not_found');
});

it('leaves a response carried by an HttpResponseException untouched', function () {
    Route::get('/v1/_probe', fn () => throw new HttpResponseException(response('teapot', 418)));

    $this->get('/v1/_probe')->assertStatus(418)->assertSee('teapot');
});

it('maps an HTTP exception by its status', function (int $status, string $code) {
    Route::get('/v1/_probe', fn () => abort($status));

    expectProblem($this->getJson('/v1/_probe'), $status, $code);
})->with([
    'unauthorized' => [401, 'unauthenticated'],
    'forbidden' => [403, 'forbidden'],
]);

it('answers a client error without a catalogue entry as validation_failed, with a fixed detail', function (int $status) {
    Route::get('/v1/_probe', fn () => abort($status, 'short and stout'));

    $response = $this->getJson('/v1/_probe');

    expectProblem($response, 422, 'validation_failed');
    expect($response->getContent())->not->toContain('short and stout');
})->with([
    'bad request' => [400],
    'unsupported media type' => [415],
    'teapot' => [418],
]);

it('answers a server error without a catalogue entry as an opaque internal_error', function (int $status) {
    Route::get('/v1/_probe', fn () => abort($status, 'upstream detail'));

    $response = $this->getJson('/v1/_probe');

    expectProblem($response, 500, 'internal_error');
    expect($response->getContent())->not->toContain('upstream detail');
})->with([
    'internal' => [500],
    'not implemented' => [501],
]);

it('renders a provider failure as 502 provider_error without the provider\'s words', function (int $status) {
    Route::get('/v1/_probe', fn () => throw ProviderException::fromStatus($status));

    // The request id is the caller's or a random ULID and may spell the status by chance: it is the one member the check leaves out.
    $response = $this->getJson('/v1/_probe', ['X-Request-Id' => "req-{$status}"]);

    expectProblem($response, 502, 'provider_error');
    expect($response->json('detail'))->toBe('The avatar provider request failed.')
        ->and(str_replace("req-{$status}", '', $response->getContent()))->not->toContain((string) $status);
})->with([400, 401, 404, 500, 503]);

it('renders a provider throttle as 429 provider_concurrency_limited', function () {
    Route::get('/v1/_probe', fn () => throw ProviderException::throttled(409));

    expectProblem($this->getJson('/v1/_probe'), 429, 'provider_concurrency_limited');
});

it('never lets a provider\'s body, header or key reach the response or the log', function () {
    $logged = captureLogs();
    Http::fake(['*' => Http::response('{"error":"bad key secret-token-123"}', 500)]);
    Route::get('/v1/_probe', function () {
        $response = Http::withToken('secret-token-123')->get('https://api.provider.example.test/v1/x');

        // What an adapter does with a failed call: keep the status, drop the response and the request.
        throw ProviderException::fromStatus($response->status());
    });

    $response = $this->getJson('/v1/_probe');

    expectProblem($response, 502, 'provider_error');
    expect($response->getContent())->not->toContain('secret-token-123')
        ->and(implode("\n", $logged->getArrayCopy()))->not->toContain('secret-token-123');
});
