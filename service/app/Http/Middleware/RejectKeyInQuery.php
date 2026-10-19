<?php

namespace App\Http\Middleware;

use App\Http\ApiProblem;
use App\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request that carries a key in its query string. URLs end up in access logs, browser
 * history and referrers, so a key that travelled in one is already compromised. The answer never
 * echoes the offending value.
 */
class RejectKeyInQuery
{
    private const KEY_PARAMETERS = ['api_key', 'key'];

    private const KEY_PREFIXES = ['hra_sk_', 'hra_pk_'];

    public function handle(Request $request, Closure $next): Response
    {
        foreach ($request->query() as $name => $value) {
            if (in_array($name, self::KEY_PARAMETERS, true) || $this->looksLikeKey($value)) {
                throw new ApiProblem(ErrorCode::ApiKeyInQuery);
            }
        }

        return $next($request);
    }

    private function looksLikeKey(mixed $value): bool
    {
        if (is_array($value)) {
            return array_any($value, fn (mixed $item) => $this->looksLikeKey($item));
        }

        return is_string($value) && array_any(self::KEY_PREFIXES, fn (string $prefix) => str_starts_with($value, $prefix));
    }
}
