<?php

namespace App\AvatarProviders\Tavus;

use App\AvatarProviders\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Sleep;

/**
 * Retries a Tavus create-conversation call when, and only when, Tavus rejects it because the
 * account is at its concurrent-conversation limit. The slot of a conversation that just ended is
 * released a few seconds after Tavus reports it ended, so waiting and trying again is what works.
 *
 * Deliberately NOT done, and not to be restored: making room by listing the account's active
 * conversations and ending them. The provider account is shared by every conversation the service
 * runs, so that would end somebody else's live conversation to start this one. The guard makes no
 * provider call of its own; it only repeats the call it is given.
 */
final class TavusConcurrencyGuard
{
    private const CAPACITY_MESSAGE = '/maximum concurrent conversations/i';

    /**
     * Makes the call once, then again while Tavus keeps answering that it is at capacity, up to the
     * configured number of retries. Anything else (success, auth failure, malformed request, 5xx)
     * is returned at once for the caller to judge.
     *
     * @param  callable(): Response  $createCall  one create attempt
     *
     * @throws ProviderException a throttle, when the account is still at capacity after the last retry
     * @throws ConnectionException when the call cannot reach Tavus; not retried
     */
    public function attempt(callable $createCall): Response
    {
        $retries = max(0, config()->integer('hr-avatar.tavus.concurrency_retries'));
        $backoffMs = max(0, config()->integer('hr-avatar.tavus.concurrency_backoff_ms'));

        $response = $createCall();

        for ($retry = 0; $retry < $retries && $this->isConcurrencyLimited($response); $retry++) {
            if ($backoffMs > 0) {
                Sleep::for($backoffMs)->milliseconds();
            }

            $response = $createCall();
        }

        if ($this->isConcurrencyLimited($response)) {
            throw ProviderException::throttled($response->status());
        }

        return $response;
    }

    /**
     * A 4xx (never a success, never a 5xx) whose own message says the limit was reached. The message
     * is only matched, never kept: nothing the provider wrote reaches an exception or a log.
     */
    public function isConcurrencyLimited(Response $response): bool
    {
        if ($response->status() < 400 || $response->status() >= 500) {
            return false;
        }

        $message = $response->json('message') ?? $response->json('error') ?? Arr::get((array) $response->json(), 'data.message');

        return is_string($message) && preg_match(self::CAPACITY_MESSAGE, $message) === 1;
    }
}
