<?php

namespace App\Http\Middleware;

use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Keys\Scope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: `scope:conversations:write`. Runs after authentication, which sets the key's scopes.
 */
class RequireScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $granted = $request->attributes->get('key_scopes');

        // A scope name that does not exist is a defect of the route definition: refuse every caller and say so.
        if (Scope::tryFrom($scope) === null) {
            Log::error('Route requires an unknown scope', ['scope' => $scope]);
        }

        if (! is_array($granted) || ! Scope::grantsNamed(array_values(array_filter($granted, is_string(...))), $scope)) {
            throw new ApiProblem(ErrorCode::InsufficientScope);
        }

        return $next($request);
    }
}
