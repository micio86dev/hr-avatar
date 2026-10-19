<?php

namespace App\Http;

use App\AvatarProviders\FailureClass;
use App\AvatarProviders\ProviderException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns every exception into a Problem+JSON response. Registered for all requests, so an
 * unmatched path or an unsupported method never produces an HTML page. The catalogue is
 * closed: a client error with no entry of its own answers 422 `validation_failed`, anything
 * else 500 `internal_error`, and an exception message is never exposed.
 */
final class ProblemRenderer
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        if ($e instanceof ApiProblem) {
            return Problem::make($request, $e->errorCode, $e->detail, $e->errors, $e->headers, $e->extra);
        }

        if ($e instanceof ProviderException) {
            return Problem::make($request, $e->failureClass() === FailureClass::Throttle ? ErrorCode::ProviderConcurrencyLimited : ErrorCode::ProviderError);
        }

        if ($e instanceof ValidationException) {
            return Problem::make($request, ErrorCode::ValidationFailed, errors: $e->errors());
        }

        if ($e instanceof AuthenticationException) {
            return Problem::make($request, ErrorCode::Unauthenticated);
        }

        if ($e instanceof HttpExceptionInterface) {
            $code = self::codeForStatus($e->getStatusCode());

            return Problem::make($request, $code, headers: self::safeHeaders($e->getHeaders()));
        }

        return Problem::make($request, ErrorCode::InternalError);
    }

    private static function codeForStatus(int $status): ErrorCode
    {
        return match ($status) {
            401 => ErrorCode::Unauthenticated,
            403 => ErrorCode::Forbidden,
            404 => ErrorCode::NotFound,
            405 => ErrorCode::MethodNotAllowed,
            413 => ErrorCode::PayloadTooLarge,
            429 => ErrorCode::RateLimited,
            503 => ErrorCode::ServiceUnavailable,
            default => $status < 500 ? ErrorCode::ValidationFailed : ErrorCode::InternalError,
        };
    }

    /**
     * Keeps the headers that carry protocol meaning (Allow, Retry-After, X-RateLimit-*).
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function safeHeaders(array $headers): array
    {
        return array_filter(
            $headers,
            fn (string $name) => in_array(strtolower($name), ['allow', 'retry-after'], true) || str_starts_with(strtolower($name), 'x-ratelimit-'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
