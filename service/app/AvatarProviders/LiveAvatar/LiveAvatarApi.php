<?php

namespace App\AvatarProviders\LiveAvatar;

use App\AvatarProviders\ProviderException;
use App\Credentials\ProviderAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one place that talks to the LiveAvatar API: the host, the key header and the mapping of a
 * failed call to a ProviderException. Everything above it (the adapter and the two registrars)
 * decides what a status means, because it differs per call (a 404 on a delete is success).
 *
 * A failure carries the status class only: the provider's body and the request, which holds the
 * key, never reach an exception message or a log.
 */
final class LiveAvatarApi
{
    /** The registry id of the HeyGen LiveAvatar provider, also the `provider` of its ledger rows. */
    public const PROVIDER_ID = 'heygen';

    private const BASE_URL = 'https://api.liveavatar.com/v1';

    /**
     * @param  'GET'|'POST'|'PATCH'|'DELETE'  $method
     * @param  array<string, mixed>  $body  sent as JSON; ignored by GET and DELETE
     * @param  int  $timeout  seconds, so a slow provider cannot hold a request open
     *
     * @throws ProviderException when there is no usable response at all (timeout, connection failure)
     */
    public function call(ProviderAuth $auth, string $method, string $path, array $body = [], int $timeout = 10): Response
    {
        $request = Http::withHeaders(['X-API-KEY' => $auth->apiKey()])->timeout($timeout);
        $url = self::BASE_URL.$path;

        try {
            return match ($method) {
                'GET' => $request->get($url),
                'POST' => $request->post($url, $body),
                'PATCH' => $request->patch($url, $body),
                'DELETE' => $request->delete($url),
            };
        } catch (ConnectionException) {
            throw ProviderException::noResponse();
        }
    }

    /**
     * @throws ProviderException for any status outside 2xx
     */
    public function orFail(Response $response): Response
    {
        return $response->successful() ? $response : throw ProviderException::fromStatus($response->status());
    }

    /**
     * The shape LiveAvatar was seen to answer for a secret id it does not know (a secret deleted in its dashboard): HTTP
     * 400, code 4000, and a message of the form "Secret with id '...' not found in your space". The message is the only
     * thing that tells it from another 4000, so a wording change on their side degrades to a plain failure (an error,
     * never a wrong object).
     */
    public function isUnknownSecret(Response $response): bool
    {
        return $response->status() === 400
            && $response->json('code') === 4000
            && str_contains((string) $response->json('message'), 'Secret');
    }

    /** A non-empty string at a dotted path of the answer, else null. */
    public function stringAt(Response $response, string $path): ?string
    {
        $value = $response->json($path);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
