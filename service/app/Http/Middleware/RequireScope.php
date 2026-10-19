<?php

namespace App\Http\Middleware;

use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Keys\Scope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: `scope:conversations:write`. Runs after authentication, which sets the key's scopes.
 */
class RequireScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $granted = $request->attributes->get('key_scopes');

        if (! is_array($granted) || ! Scope::grants(array_values(array_filter($granted, is_string(...))), Scope::from($scope))) {
            throw new ApiProblem(ErrorCode::InsufficientScope);
        }

        return $next($request);
    }
}
