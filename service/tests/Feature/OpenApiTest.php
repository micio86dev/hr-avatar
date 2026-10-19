<?php

use App\Http\OpenApi\ProblemDocumentation;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
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

/*
 * The shared error body and the answers every endpoint can give, documented once and referenced.
 */
function operationsOf(array $document): array
{
    $operations = [];

    foreach ($document['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            $operations["$method $path"] = $operation;
        }
    }

    return $operations;
}

function referencedResponse(array $document, array $response): array
{
    return isset($response['$ref']) ? $document['components']['responses'][basename($response['$ref'])] : $response;
}

it('describes the problem body once, as a component with the stable members', function () {
    $problem = openApiDocument()['components']['schemas']['Problem'] ?? null;

    expect($problem)->not->toBeNull()
        ->and(array_keys($problem['properties']))->toBe(['type', 'title', 'status', 'detail', 'code', 'request_id', 'errors'])
        ->and($problem['required'])->toBe(['type', 'title', 'status', 'detail', 'code', 'request_id'])
        ->and($problem['properties']['status']['type'])->toBe('integer')
        ->and($problem['properties']['errors']['additionalProperties']['items']['type'])->toBe('string');
});

it('gives every error body the problem schema and never a bare string or the framework\'s own shape', function () {
    $document = openApiDocument();
    $seen = 0;

    foreach (operationsOf($document) as $key => $operation) {
        foreach ($operation['responses'] as $code => $response) {
            $resolved = referencedResponse($document, $response);

            if ((int) $code < 400) {
                continue;
            }

            $seen++;
            expect(array_keys($resolved['content'] ?? []))->toBe(['application/problem+json'], "$key $code")
                ->and($resolved['content']['application/problem+json']['schema'])->toBe(['$ref' => '#/components/schemas/Problem'], "$key $code");
        }
    }

    expect($seen)->toBeGreaterThan(40)
        ->and($document['components']['responses'])->not->toHaveKeys(['ValidationException', 'ModelNotFoundException']);
});

it('documents 401 and 429 on every operation that takes a credential', function () {
    $document = openApiDocument();
    $secured = array_filter(operationsOf($document), fn (array $operation) => ($operation['security'] ?? []) !== []);

    expect($secured)->not->toBeEmpty();
    foreach ($secured as $key => $operation) {
        expect(array_map('strval', array_keys($operation['responses'])))->toContain('401', '429');
        expect($operation['responses']['401'])->toBe(['$ref' => '#/components/responses/Unauthenticated'], $key);
        // The common answer, or the operation's own 429 with the budget one added to it.
        expect($operation['responses']['429'] === ['$ref' => '#/components/responses/RateLimited'] || str_contains($operation['responses']['429']['description'] ?? '', '`rate_limited`'))->toBeTrue($key);
    }
});

it('documents 404 on every operation with a path parameter', function () {
    $document = openApiDocument();
    $withParameter = array_filter(operationsOf($document), fn (array $operation, string $key) => str_contains(explode(' ', $key)[1], '{'), ARRAY_FILTER_USE_BOTH);

    expect($withParameter)->not->toBeEmpty();
    foreach ($withParameter as $key => $operation) {
        expect($operation['responses'], $key)->toHaveKey('404');
        expect(referencedResponse($document, $operation['responses']['404'])['content']['application/problem+json']['schema'])->toBe(['$ref' => '#/components/schemas/Problem']);
    }
});

it('keeps the specific answers an operation documents itself', function () {
    $responses = openApiDocument()['paths']['/client/conversations/{conversation}/connect']['post']['responses'];

    expect($responses['429']['description'])->toContain('provider_concurrency_limited')->toContain('rate_limited')
        ->and($responses['422']['description'])->toContain('provider_credential_missing');
});

it('documents the common answers once each, with the stable code', function () {
    $responses = openApiDocument()['components']['responses'];

    expect($responses['Unauthenticated']['description'])->toContain('unauthenticated')
        ->and($responses['NotFound']['description'])->toContain('not_found')
        ->and($responses['RateLimited']['description'])->toContain('rate_limited')
        ->and($responses['ValidationFailed']['description'])->toContain('validation_failed');
});

it('adds none of them to the health endpoints, which take no credential and no throttle', function (string $path) {
    expect(array_map('strval', array_keys(openApiDocument()['paths'][$path]['get']['responses'])))->not->toContain('401', '404', '429');
})->with(['/health', '/health/ready']);

it('documents every registered route with the security scheme its middleware names', function () {
    $document = openApiDocument();
    $checked = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        $expected = match (true) {
            in_array('auth.secret', $middleware, true) => [['secretKey' => []]],
            in_array('auth.client', $middleware, true) => [['clientSecret' => [], 'publishableKey' => []]],
            default => [],
        };
        $path = '/'.ltrim(substr('/'.ltrim($route->uri(), '/'), strlen('/v1')), '/');

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $checked++;
            expect($document['paths'][$path][strtolower($method)]['security'] ?? null)->toBe($expected, "$method $path");
        }
    }

    expect($checked)->toBeGreaterThan(30);
});

it('documents each answer code once per operation, whatever form the framework gave it', function () {
    $document = new OpenApi('3.1.0');
    $document->setComponents(new Components);
    $document->components->responses['Illuminate\\Database\\Eloquent\\ModelNotFoundException'] = Response::make(404)->setDescription('Not found');
    $document->components->responses['Illuminate\\Validation\\ValidationException'] = Response::make(422)->setDescription('Invalid');
    $document->components->responses['Teapot'] = Response::make(418)->setDescription('A response this service does not know');
    $operation = Operation::make('get')->addResponse(Response::make(200)->setDescription('ok'))
        ->addResponse(new Reference('responses', 'Illuminate\\Database\\Eloquent\\ModelNotFoundException', $document->components))
        ->addResponse(new Reference('responses', 'Illuminate\\Validation\\ValidationException', $document->components))
        ->addResponse(new Reference('responses', 'Teapot', $document->components));
    $operation->security = [new SecurityRequirement(['secretKey' => []])];
    $document->addPath(Path::make('things/{thing}')->addOperation($operation));

    ProblemDocumentation::document($document);

    $codes = array_map(fn ($r) => $r instanceof Reference ? $r->fullName : (int) $r->code, $operation->responses);
    expect($codes)->toBe([200, 'NotFound', 'ValidationFailed', 'Teapot', 'Unauthenticated', 'RateLimited'])
        ->and(array_keys($document->components->responses))->not->toContain('Illuminate\\Database\\Eloquent\\ModelNotFoundException');
});
