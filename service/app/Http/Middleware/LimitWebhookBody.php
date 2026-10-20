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
 * header is only a claim and a chunked body has none: it is read from the stream, never more than the
 * limit plus one byte.
 */
class LimitWebhookBody
{
    public const MAX_BYTES = 256 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $declared = $request->headers->get('Content-Length');

        if (is_numeric($declared) && (int) $declared > self::MAX_BYTES) {
            throw new ApiProblem(ErrorCode::PayloadTooLarge);
        }

        // The header is only a claim, and a chunked body has none: read at most one byte past the limit from the
        // stream instead of buffering whatever is sent. What is within the limit is read in full afterwards.
        $body = stream_get_contents($request->getContent(true), self::MAX_BYTES + 1);

        if ($body === false || strlen($body) > self::MAX_BYTES) {
            throw new ApiProblem(ErrorCode::PayloadTooLarge);
        }

        return $next($request);
    }
}
