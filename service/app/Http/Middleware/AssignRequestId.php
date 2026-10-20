<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlates one request: keeps a well-formed inbound X-Request-Id or mints a ULID, echoes it
 * on the response and shares it with the log context. The route name joins that context when
 * the route matches (see AppServiceProvider); the path never does.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    private const ATTRIBUTE = 'request_id';

    private const VALID = '/^[A-Za-z0-9._-]{1,64}\z/';

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::for($request);

        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /**
     * The id of this request, resolved once and reused by every later reader (the error renderer
     * included, which may run before or without this middleware).
     */
    public static function for(Request $request): string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($id)) {
            return $id;
        }

        $supplied = $request->headers->get(self::HEADER);
        $id = is_string($supplied) && preg_match(self::VALID, $supplied) === 1
            ? $supplied
            : (string) Str::ulid();

        $request->attributes->set(self::ATTRIBUTE, $id);

        return $id;
    }
}
