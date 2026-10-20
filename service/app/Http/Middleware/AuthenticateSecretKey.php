<?php

namespace App\Http\Middleware;

use App\Accounts\CurrentAccount;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Keys\FailedAuthLimiter;
use App\Keys\KeyMode;
use App\Keys\KeyResolver;
use App\Keys\KeyType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a server-to-server request by its secret key and binds the key's account for the
 * rest of the request. Every 401 counts against the caller's failed-auth budget; a live key
 * presented from a browser origin is refused outright, because a secret key in a browser is
 * already leaked, and that refusal counts against the same budget.
 */
class AuthenticateSecretKey
{
    public function __construct(private readonly KeyResolver $resolver, private readonly FailedAuthLimiter $failures) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->failures->ensureAllowed($request);

        try {
            $key = $this->resolver->resolve($request->bearerToken() ?? '', KeyType::Secret);
        } catch (ApiProblem $problem) {
            $this->failures->recordFailure($request);

            throw $problem;
        }

        if ($key->mode === KeyMode::Live && $request->headers->has('Origin')) {
            // Not free: each refusal cost a key lookup, and a leaked key sent from browsers must not get a free budget.
            $this->failures->recordFailure($request);

            throw new ApiProblem(ErrorCode::SecretKeyOriginForbidden);
        }

        $request->attributes->set('api_key_id', $key->id);
        $request->attributes->set('key_mode', $key->mode->value);
        $request->attributes->set('key_scopes', $key->scopes);

        return CurrentAccount::runAs($key->account_id, fn () => $next($request));
    }
}
