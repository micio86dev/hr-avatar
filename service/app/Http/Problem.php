<?php

namespace App\Http;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds RFC 9457 Problem+JSON responses. Every non-2xx body of the service goes through here.
 */
final class Problem
{
    public const CONTENT_TYPE = 'application/problem+json';

    /**
     * @param  array<string, list<string>>|null  $errors  field name => messages
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra  additional top-level members (for example `checks`); never override the core members
     */
    public static function make(
        Request $request,
        ErrorCode $code,
        ?string $detail = null,
        ?array $errors = null,
        array $headers = [],
        array $extra = [],
    ): JsonResponse {
        $requestId = AssignRequestId::for($request);
        $status = $code->status();

        $body = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status],
            'status' => $status,
            'detail' => $detail ?? $code->defaultDetail(),
            'code' => $code->value,
            'request_id' => $requestId,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return new JsonResponse(
            [...$extra, ...$body],
            $status,
            [...$headers, 'Content-Type' => self::CONTENT_TYPE, AssignRequestId::HEADER => $requestId],
        );
    }
}
