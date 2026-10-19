<?php

use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\Route;

function openApiDocument(): array
{
    return app(Generator::class)();
}

it('documents every registered /v1 route and method', function () {
    $expected = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn (string $method) => $method === 'HEAD')
            ->map(fn (string $method) => strtolower($method).' /'.ltrim($route->uri(), '/')))
        ->sort()->values()->all();

    $documented = collect(openApiDocument()['paths'])
        ->flatMap(fn (array $operations, string $path) => collect(array_keys($operations))
            ->map(fn (string $method) => $method.' /v1'.$path))
        ->sort()->values()->all();

    expect($expected)->not->toBeEmpty()->and($documented)->toBe($expected);
});

it('declares no security on the health endpoints', function (string $path) {
    expect(openApiDocument()['paths'][$path]['get'])->toHaveKey('security')
        ->and(openApiDocument()['paths'][$path]['get']['security'])->toBe([]);
})->with(['/health', '/health/ready']);

it('declares the three credential schemes, and none for the path token', function () {
    $schemes = openApiDocument()['components']['securitySchemes'];

    expect($schemes)->toHaveKeys(['secretKey', 'clientSecret', 'publishableKey'])->not->toHaveKey('providerToken')
        ->and($schemes['secretKey'])->toMatchArray(['type' => 'http', 'scheme' => 'bearer'])
        ->and($schemes['clientSecret'])->toMatchArray(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'])
        ->and($schemes['publishableKey'])->toMatchArray(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-HRA-Publishable-Key']);
});

it('does not add a document-wide security requirement', function () {
    expect(openApiDocument())->not->toHaveKey('security');
});

it('takes the document version from the VERSION file', function () {
    expect(openApiDocument()['info']['version'])->toBe(trim(file_get_contents(base_path('VERSION'))));
});

it('attaches the security scheme that the route middleware names', function (string $uri, array $middleware, array $expected) {
    Route::get($uri, fn () => ['ok' => true])->middleware($middleware);

    $path = '/'.ltrim(substr($uri, strlen('/v1')), '/');

    expect(openApiDocument()['paths'][$path]['get']['security'])->toBe($expected);
})->with([
    'secret key' => ['/v1/_secret', ['auth.secret'], [['secretKey' => []]]],
    'publishable key plus client secret' => ['/v1/_client', ['auth.client'], [['clientSecret' => [], 'publishableKey' => []]]],
    'path token (a path parameter, not a scheme)' => ['/v1/webhooks/providers/{provider}/{token}', [], []],
    'unauthenticated' => ['/v1/_open', [], []],
]);

it('documents the Problem response of the readiness endpoint', function () {
    $responses = openApiDocument()['paths']['/health/ready']['get']['responses'];

    expect($responses)->toHaveKey('503')
        ->and($responses['503']['content'])->toHaveKey('application/problem+json');
});

it('describes the API and names a contact', function () {
    $info = openApiDocument()['info'];

    expect($info['description'])->toBeString()->not->toBeEmpty()
        ->and($info['contact']['name'])->toBe('HR-Avatar API');
});

it('declares every tag an operation uses', function () {
    $document = openApiDocument();
    $used = collect($document['paths'])->flatten(1)->pluck('tags')->flatten()->unique()->sort()->values()->all();

    expect($used)->not->toBeEmpty()
        ->and(collect($document['tags'])->pluck('name')->sort()->values()->all())->toBe($used);
});

it('gives every operation a description', function () {
    $operations = collect(openApiDocument()['paths'])->flatMap(fn (array $methods) => $methods);

    expect($operations)->not->toBeEmpty();
    $operations->each(fn (array $operation) => expect($operation['description'] ?? '')->not->toBeEmpty());
});
