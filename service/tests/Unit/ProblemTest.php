<?php

use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Http\Problem;
use Illuminate\Http\Request;

const CATALOGUE = [
    'api_key_in_query' => 400,
    'unauthenticated' => 401,
    'key_expired' => 401,
    'key_revoked' => 401,
    'client_secret_invalid' => 401,
    'client_secret_expired' => 401,
    'insufficient_scope' => 403,
    'forbidden' => 403,
    'origin_not_allowed' => 403,
    'secret_key_origin_forbidden' => 403,
    'not_found' => 404,
    'method_not_allowed' => 405,
    'already_connected' => 409,
    'invalid_state' => 409,
    'conversation_closed' => 409,
    'config_not_synced' => 409,
    'config_in_use' => 409,
    'payload_too_large' => 413,
    'validation_failed' => 422,
    'provider_credential_missing' => 422,
    'llm_credential_missing' => 422,
    'rate_limited' => 429,
    'provider_concurrency_limited' => 429,
    'internal_error' => 500,
    'provider_error' => 502,
    'service_unavailable' => 503,
];

it('ships exactly the documented error catalogue', function () {
    expect(collect(ErrorCode::cases())->mapWithKeys(fn (ErrorCode $c) => [$c->value => $c->status()])->all())
        ->toBe(CATALOGUE);
});

it('maps each code to its HTTP status', function (string $code, int $status) {
    expect(ErrorCode::from($code)->status())->toBe($status);
})->with(fn () => collect(CATALOGUE)->map(fn (int $status, string $code) => [$code, $status])->all());

it('gives every code a fixed English detail sentence', function (ErrorCode $code) {
    expect($code->defaultDetail())->toBeString()->not->toBeEmpty()->toEndWith('.');
})->with(ErrorCode::cases());

it('builds an RFC 9457 body with the request id and the reason phrase', function () {
    $request = Request::create('/v1/things', 'GET', server: ['HTTP_X_REQUEST_ID' => 'req-1']);

    $response = Problem::make($request, ErrorCode::NotFound);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->headers->get('X-Request-Id'))->toBe('req-1')
        ->and($response->getData(true))->toBe([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => ErrorCode::NotFound->defaultDetail(),
            'code' => 'not_found',
            'request_id' => 'req-1',
        ]);
});

it('carries a custom detail, field errors, extra members and headers', function () {
    $response = Problem::make(
        Request::create('/v1/things'),
        ErrorCode::ValidationFailed,
        detail: 'Bad input.',
        errors: ['name' => ['The name field is required.']],
        headers: ['Retry-After' => '7'],
        extra: ['checks' => ['redis' => 'failed']],
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Retry-After'))->toBe('7')
        ->and($response->getData(true))->toMatchArray([
            'detail' => 'Bad input.',
            'errors' => ['name' => ['The name field is required.']],
            'checks' => ['redis' => 'failed'],
            'title' => 'Unprocessable Content',
        ]);
});

it('exposes the problem fields on the exception that carries them', function () {
    $problem = new ApiProblem(ErrorCode::ConfigInUse, 'Still referenced.', headers: ['X-Hint' => 'a']);

    expect($problem->errorCode)->toBe(ErrorCode::ConfigInUse)
        ->and($problem->detail)->toBe('Still referenced.')
        ->and($problem->headers)->toBe(['X-Hint' => 'a'])
        ->and($problem->errors)->toBeNull()
        ->and($problem->getMessage())->toBe('Still referenced.');
});

it('never lets an extra member overwrite a core member', function () {
    $response = Problem::make(
        Request::create('/v1/things', server: ['HTTP_X_REQUEST_ID' => 'req-9']),
        ErrorCode::NotFound,
        extra: ['code' => 'forged', 'status' => 200, 'request_id' => 'forged', 'title' => 'forged', 'checks' => ['x' => 'ok']],
    );

    expect($response->getData(true))->toMatchArray([
        'code' => 'not_found',
        'status' => 404,
        'request_id' => 'req-9',
        'title' => 'Not Found',
        'checks' => ['x' => 'ok'],
    ]);
});
