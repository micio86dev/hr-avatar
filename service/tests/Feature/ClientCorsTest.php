<?php

use App\Http\Middleware\ClientCors;
use App\Keys\ClientSecret;
use App\Keys\KeyType;
use App\Models\Account;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->account = Account::factory()->create();
    [, $this->pk] = issueKey($this->account, type: KeyType::Publishable);
    $this->token = (new ClientSecret)->mint('conv_one', $this->account->id, now(), 1800)->token;

    Route::middleware(['api', 'auth.client'])->post('/v1/client/conversations/{conversation}/probe', fn () => ['ok' => true]);
});

function realRequest(array $overrides = [])
{
    $headers = array_filter([
        'X-HRA-Publishable-Key' => test()->pk,
        'Authorization' => 'Bearer '.test()->token,
        'Origin' => 'https://app.example.com',
        ...$overrides,
    ], fn ($value) => $value !== null);

    return test()->postJson('/v1/client/conversations/conv_one/probe', [], $headers);
}

function preflight(string $path = '/v1/client/conversations/conv_one/connect', array $headers = [])
{
    $headers = array_filter([
        'Origin' => 'https://anywhere.example.org',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'authorization,content-type,x-hra-publishable-key',
        ...$headers,
    ], fn ($value) => $value !== null);

    return test()->call('OPTIONS', $path, [], [], [], test()->transformHeadersToServerVars($headers));
}

it('answers a preflight with 204 and the allowed methods, headers and max age, without any credential', function () {
    $response = preflight();

    $response->assertNoContent();
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://anywhere.example.org')
        ->and($response->headers->get('Access-Control-Allow-Methods'))->toBe('POST')
        ->and($response->headers->get('Access-Control-Allow-Headers'))->toBe('Authorization, Content-Type, X-HRA-Publishable-Key, X-Request-Id')
        ->and($response->headers->get('Access-Control-Max-Age'))->toBe('600')
        ->and($response->headers->get('Vary'))->toContain('Origin')
        ->and($response->headers->get('X-Request-Id'))->not->toBeEmpty()
        ->and($response->getContent())->toBe('');
});

it('echoes whichever well-formed origin asks, because a preflight carries no key to check it against', function (string $origin) {
    expect(preflight(headers: ['Origin' => $origin])->headers->get('Access-Control-Allow-Origin'))->toBe($origin);
})->with(['https://one.example.com', 'http://localhost:3000', 'https://two.example.com:8443']);

it('does not echo an origin that is not an origin', function (string $origin) {
    $response = preflight(headers: ['Origin' => $origin]);

    $response->assertNoContent();
    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
})->with(['null', 'garbage', 'https://app.example.com/path', 'https://*.example.com']);

it('leaves an OPTIONS request that is not a preflight alone', function (array $headers) {
    $response = preflight(headers: $headers);

    expect($response->status())->not->toBe(204)
        ->and($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
})->with([
    'without an origin' => [['Origin' => null]],
    'without a requested method' => [['Access-Control-Request-Method' => null]],
]);

it('answers preflights only under /v1/client', function (string $path) {
    $response = preflight($path);

    expect($response->status())->not->toBe(204)
        ->and($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
})->with(['/v1/api-keys', '/v1/health', '/v1/clients', '/v1/other/client/conversations/x']);

it('emits the allow headers on a response only after the allowlist passed', function () {
    $response = realRequest()->assertOk();

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.com')
        ->and($response->headers->get('Access-Control-Expose-Headers'))->toBe('X-Request-Id')
        ->and($response->headers->get('Vary'))->toContain('Origin');
});

it('keeps the allow headers on an error that follows a passed allowlist, so the browser can read it', function () {
    $response = realRequest(['Authorization' => 'Bearer garbage']);

    $response->assertStatus(401);
    expect($response->json('code'))->toBe('client_secret_invalid')
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.com');
});

it('emits no allow header for a rejected origin or a request that never reached the allowlist', function (array $overrides) {
    $response = realRequest($overrides);

    expect($response->status())->toBeIn([401, 403])
        ->and($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($response->headers->has('Access-Control-Expose-Headers'))->toBeFalse();
})->with([
    'another origin' => [['Origin' => 'https://evil.example.com']],
    'no origin' => [['Origin' => null]],
    'no publishable key' => [['X-HRA-Publishable-Key' => null]],
]);

it('marks client responses as varying by origin even when it allows none', function () {
    expect(realRequest(['Origin' => 'https://evil.example.com'])->headers->get('Vary'))->toContain('Origin');
});

it('adds nothing to a route outside /v1/client', function () {
    $response = $this->getJson('/v1/health', ['Origin' => 'https://app.example.com']);

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and((string) $response->headers->get('Vary'))->not->toContain('Origin');
});

it('is the only CORS source: Laravel\'s HandleCors is not in the global stack', function () {
    $stack = app(Kernel::class)->getGlobalMiddleware();

    expect($stack)->toContain(ClientCors::class)->not->toContain(HandleCors::class);
});

// Replacing HandleCors removed CORS from every route outside /v1/client. That is the intent: only the browser SDK calls the
// API from a page; every other route is server-to-server and a secret key must never be usable from a browser origin.
it('gives a route outside /v1/client neither a preflight answer nor allow headers, from any origin', function (string $method, string $path) {
    $response = $this->call($method, $path, [], [], [], $this->transformHeadersToServerVars([
        'Origin' => 'https://app.example.com',
        'Access-Control-Request-Method' => 'GET',
        'Access-Control-Request-Headers' => 'authorization',
    ]));

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($response->headers->has('Access-Control-Allow-Methods'))->toBeFalse()
        ->and($response->headers->has('Access-Control-Allow-Headers'))->toBeFalse();
})->with([
    'a preflight of a secret-key route' => ['OPTIONS', '/v1/conversations'],
    'a read of a secret-key route' => ['GET', '/v1/conversations'],
    'a webhook endpoint listing' => ['GET', '/v1/webhook-endpoints'],
    'the inbound provider webhook' => ['POST', '/v1/webhooks/providers/tavus/token'],
]);
