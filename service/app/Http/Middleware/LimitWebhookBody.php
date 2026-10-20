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
 *
 * What it does not bound is the memory of a JSON request: the framework decodes a JSON body when it captures
 * the request, before any middleware runs. That cap is the web server's (`client_max_body_size` in the shipped
 * nginx configuration); this check is what answers an oversize body with the stable 413 problem.
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
        // stream instead of buffering whatever is sent.
        $body = stream_get_contents($request->getContent(true), self::MAX_BYTES + 1);

        if ($body === false || strlen($body) > self::MAX_BYTES) {
            throw new ApiProblem(ErrorCode::PayloadTooLarge);
        }

        // Reading drained the stream, and whether the request can read it a second time is up to how it was built.
        // What was read is handed back, so everything after this sees the same body whatever the request's input is.
        $headers = $request->headers->all();
        $request->initialize($request->query->all(), $request->request->all(), $request->attributes->all(), $request->cookies->all(), $request->files->all(), $request->server->all(), $body);
        $request->headers->replace($headers);

        return $next($request);
    }
}
