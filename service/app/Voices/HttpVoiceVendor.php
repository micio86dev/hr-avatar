<?php

namespace App\Voices;

use App\AvatarProviders\ProviderException;
use Closure;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * What the two vendors that speak plain HTTP share: a failed call becomes a ProviderException that
 * carries the status and nothing of what the vendor wrote or what was sent (the request holds the key).
 */
abstract class HttpVoiceVendor implements VoiceVendor
{
    /** Bounds a call so a slow vendor cannot hold a request or a worker open. */
    protected function timeout(): int
    {
        return (int) config('hr-avatar.voice_preview.timeout_seconds');
    }

    /** The language a vendor states for a voice, lowercased; null when it states none. */
    protected static function language(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : null;
    }

    /**
     * @param  Closure(): Response  $request
     *
     * @throws ProviderException
     */
    protected function send(Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw ProviderException::noResponse();
        }

        return $response->successful() ? $response : throw ProviderException::fromStatus($response->status());
    }

    /**
     * The audio of a streamed answer, read in chunks and never beyond the cap, so a vendor cannot make this
     * process buffer more than a sample is worth. Empty, over the cap, or a transfer that dies mid-body is
     * a failure; the message of that last one holds the url and the request and is dropped.
     *
     * @throws ProviderException
     */
    protected function boundedBody(Response $response): string
    {
        $max = (int) config('hr-avatar.voice_preview.max_bytes');
        $body = '';

        try {
            $stream = $response->toPsrResponse()->getBody();

            while (! $stream->eof()) {
                $body .= $stream->read(65536);

                if (strlen($body) > $max) {
                    throw ProviderException::noResponse();
                }
            }
        } catch (ProviderException $failure) {
            throw $failure;
        } catch (RuntimeException|GuzzleException) {
            throw ProviderException::noResponse();
        }

        return $body !== '' ? $body : throw ProviderException::noResponse();
    }

    /**
     * @return array<mixed>
     *
     * @throws ProviderException when the answer is not JSON
     */
    protected function json(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : throw ProviderException::noResponse();
    }
}
