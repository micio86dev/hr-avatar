<?php

namespace App\AvatarProviders;

use RuntimeException;

/**
 * The one error every provider failure becomes. It carries the HTTP status and a generic message,
 * and by construction nothing else: there is no way to attach the provider's response body, the
 * request headers (which hold the API key) or the exception they came from. Logs get the status
 * class only (see context()).
 */
final class ProviderException extends RuntimeException
{
    private function __construct(string $message, private readonly FailureClass $class, private readonly ?int $status)
    {
        parent::__construct($message);
    }

    /** Classified by status: 429 throttles, any other 4xx is a request the provider refused, the rest is the provider failing. */
    public static function fromStatus(int $status): self
    {
        $class = match (true) {
            $status === 429 => FailureClass::Throttle,
            $status >= 400 && $status < 500 => FailureClass::ClientError,
            default => FailureClass::Upstream,
        };

        return new self("Provider request failed (status {$status})", $class, $status);
    }

    /** A throttle that is not a 429: the provider says it is at capacity with another status. */
    public static function throttled(int $status): self
    {
        return new self("Provider request failed (status {$status})", FailureClass::Throttle, $status);
    }

    /** No usable response: a timeout, a connection failure, or a body that makes no sense. */
    public static function noResponse(): self
    {
        return new self('Provider request failed (no response)', FailureClass::Upstream, null);
    }

    public function failureClass(): FailureClass
    {
        return $this->class;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    /** `4xx`, `5xx`, or `none`: what a log line may say about the failure. */
    public function statusClass(): string
    {
        return $this->status === null ? 'none' : intdiv($this->status, 100).'xx';
    }

    /**
     * Laravel adds this to the log entry of a reported exception.
     *
     * @return array{status_class: string}
     */
    public function context(): array
    {
        return ['status_class' => $this->statusClass()];
    }
}
