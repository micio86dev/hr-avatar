<?php

namespace App\Http\Middleware;

use App\Http\ApiProblem;
use App\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a provider callback body above 256 KB. The declared Content-Length is checked first, so an
 * honest oversize request is refused without reading it; the body itself is checked too, because the
 * header is only a claim.
 */
class LimitWebhookBody
{
    public const MAX_BYTES = 256 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $declared = $request->headers->get('Content-Length');

        if ((is_numeric($declared) && (int) $declared > self::MAX_BYTES) || strlen($request->getContent()) > self::MAX_BYTES) {
            throw new ApiProblem(ErrorCode::PayloadTooLarge);
        }

        return $next($request);
    }
}
