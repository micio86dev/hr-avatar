<?php

namespace App\Http\OpenApi;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Declares the credential schemes once and attaches the right one to each operation from the
 * route's own middleware, so a route cannot be documented with a different scheme than it enforces.
 *
 * The provider webhook token is deliberately not a scheme: it is a URL path segment, OpenAPI has
 * no path-located credential, and a bearer or apiKey declaration would tell generated clients to
 * send it in the wrong place. That operation documents `{token}` as a path parameter instead.
 */
final class SecurityDocumentation
{
    public static function document(OpenApi $document): void
    {
        $components = $document->components;

        $components->addSecurityScheme('secretKey', SecurityScheme::http('bearer')->as('secretKey')
            ->setDescription('Server-to-server secret key (`hra_sk_...`) sent as `Authorization: Bearer`.'));
        $components->addSecurityScheme('clientSecret', SecurityScheme::http('bearer', 'JWT')->as('clientSecret')
            ->setDescription('Per-conversation `client_secret` JWT sent as `Authorization: Bearer`, always together with the publishable key.'));
        $components->addSecurityScheme('publishableKey', SecurityScheme::apiKey('header', 'X-HRA-Publishable-Key')->as('publishableKey')
            ->setDescription('Browser-safe publishable key (`hra_pk_...`); it grants nothing without the client secret.'));
    }

    public static function operation(Operation $operation, RouteInfo $routeInfo): void
    {
        $route = $routeInfo->route;
        $middleware = $route->gatherMiddleware();

        $operation->security = match (true) {
            in_array('auth.secret', $middleware, true) => [new SecurityRequirement(['secretKey' => []])],
            in_array('auth.client', $middleware, true) => [new SecurityRequirement(['clientSecret' => [], 'publishableKey' => []])],
            default => [],
        };
    }
}
