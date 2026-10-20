<?php

namespace App\Keys;

use App\Http\ApiProblem;
use App\Http\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Slows credential guessing: after 30 failed authentications within a minute an address is
 * refused before its credentials are even looked at. The budget belongs to the address, not to
 * the key, because a guesser has no valid key to count against.
 */
final class FailedAuthLimiter
{
    private const WINDOW_SECONDS = 60;

    /**
     * @throws ApiProblem rate_limited when the address has used up its failures
     */
    public function ensureAllowed(Request $request): void
    {
        $bucket = $this->bucket($request);

        if (RateLimiter::tooManyAttempts($bucket, $this->maxAttempts())) {
            throw new ApiProblem(ErrorCode::RateLimited, headers: ['Retry-After' => (string) max(1, RateLimiter::availableIn($bucket))]);
        }
    }

    public function recordFailure(Request $request): void
    {
        RateLimiter::hit($this->bucket($request), self::WINDOW_SECONDS);
    }

    private function bucket(Request $request): string
    {
        return 'failed-auth:'.$request->ip();
    }

    private function maxAttempts(): int
    {
        return (int) config('hr-avatar.rate_limits.failed_auth');
    }
}
