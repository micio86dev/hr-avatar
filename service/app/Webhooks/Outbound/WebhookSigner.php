<?php

namespace App\Webhooks\Outbound;

/**
 * Signs outbound webhook bodies and verifies signatures: the scheme a receiver implements.
 *
 * The signature is `v1=` plus the lowercase hex HMAC-SHA256, keyed with the endpoint secret, of the string
 * `"{timestamp}.{body}"`, where the body is the exact bytes sent. Every attempt is signed afresh with
 * the time of that attempt; the body and the event id stay the same. A verifier compares in constant time
 * and refuses a timestamp more than 300 seconds from its own clock, in either direction.
 */
final class WebhookSigner
{
    /** How far, in seconds, a delivery's timestamp may be from the verifier's clock. */
    public const TOLERANCE_SECONDS = 300;

    /**
     * The one place a payload becomes wire bytes: whoever signs and sends a body takes it from here and
     * never encodes the array a second time, which would sign bytes other than the ones sent. Slashes and
     * unicode are left as written, so a receiver that re-serializes with defaults does not match by accident.
     *
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * The value of the `X-HRA-Signature` header for these exact bytes.
     */
    public function sign(#[\SensitiveParameter] string $secret, int $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Whether `$header` is the signature of `$body` at `$timestamp` under `$secret`, and the timestamp is
     * within the tolerance of `$now` (unix seconds, the verifier's clock).
     */
    public function verify(#[\SensitiveParameter] string $secret, string $header, int $timestamp, string $body, int $now): bool
    {
        if (abs($now - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals($this->sign($secret, $timestamp, $body), $header);
    }
}
