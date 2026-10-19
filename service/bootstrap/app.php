<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateClient;
use App\Http\Middleware\AuthenticateSecretKey;
use App\Http\Middleware\ClientCors;
use App\Http\Middleware\RejectKeyInQuery;
use App\Http\Middleware\RequireScope;
use App\Http\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        // The host's own text is carried as sent: trimming would change an opaque reference or a prompt.
        $middleware->trimStrings(except: ['external_ref', 'system_prompt', 'opening_text']);
        // One CORS source: HandleCors decides from static config before authentication, so it cannot
        // tie the allow header to a key's own origin allowlist.
        $middleware->replace(HandleCors::class, ClientCors::class);
        $middleware->alias([
            'auth.secret' => AuthenticateSecretKey::class,
            'auth.client' => AuthenticateClient::class,
            'scope' => RequireScope::class,
        ]);
        // Authentication must run before the per-key rate limit (which reads the key) and before
        // route bindings (which resolve account-scoped models). Both come later in Laravel's priority list.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateSecretKey::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateClient::class);
        // A key in the query string is named before credentials are judged.
        $middleware->prependToPriorityList(before: AuthenticateSecretKey::class, prepend: RejectKeyInQuery::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(fn (Throwable $e, Request $request) => ProblemRenderer::render($e, $request));
    })->create();
