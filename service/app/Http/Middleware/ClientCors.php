<?php

namespace App\Http\Middleware;

use App\Keys\OriginNormalizer;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only CORS source, limited to `v1/client/*`.
 *
 * A preflight carries no key, so it cannot be checked against an allowlist: it is answered for
 * any well-formed origin, and the real request enforces the allowlist. A real response gets the
 * allow header only once AuthenticateClient has marked the request's origin as allowed, so a
 * rejected origin never receives one.
 */
class ClientCors
{
    private const PATH = 'v1/client/*';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is(self::PATH)) {
            return $next($request);
        }

        if ($this->isPreflight($request)) {
            return $this->preflight($request);
        }

        $response = $next($request);
        $response->setVary('Origin', false);

        $origin = $request->attributes->get(AuthenticateClient::ORIGIN_ATTRIBUTE);
        if (is_string($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Expose-Headers', AssignRequestId::HEADER);
        }

        return $response;
    }

    private function isPreflight(Request $request): bool
    {
        return $request->isMethod('OPTIONS')
            && $request->headers->has('Origin')
            && $request->headers->has('Access-Control-Request-Method');
    }

    private function preflight(Request $request): Response
    {
        $response = response()->noContent();
        $response->setVary('Origin', false);

        if ($this->isOrigin((string) $request->headers->get('Origin'))) {
            $response->headers->set('Access-Control-Allow-Origin', (string) $request->headers->get('Origin'));
        }

        $response->headers->set('Access-Control-Allow-Methods', 'POST');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, '.AuthenticateClient::PUBLISHABLE_HEADER.', '.AssignRequestId::HEADER);
        $response->headers->set('Access-Control-Max-Age', '600');

        return $response;
    }

    /** Only a canonical origin is echoed: `null` or a value with a path is not one. */
    private function isOrigin(string $value): bool
    {
        try {
            return OriginNormalizer::normalize($value) === $value;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
