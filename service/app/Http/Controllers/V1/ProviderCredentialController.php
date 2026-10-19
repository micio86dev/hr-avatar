<?php

namespace App\Http\Controllers\V1;

use App\Credentials\CredentialSource;
use App\Credentials\LastFour;
use App\Http\Requests\PutCredentialRequest;
use App\Http\Resources\ProviderCredentialResource;
use App\Models\ProviderCredential;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The account's keys for avatar and voice providers. Write-only: a key is never returned.
 * Requires the `credentials:manage` scope.
 */
#[Group('Credentials')]
class ProviderCredentialController
{
    /**
     * List provider credentials
     *
     * One entry per credential provider. `source` says whose key a call would use: `account` (the
     * account stored one), `platform` (only the platform key is set) or `none`. `last_four` is
     * shown only for an account key of 12 characters or more.
     *
     * @return array{data: list<array{provider: string, source: CredentialSource, last_four: string|null}>}
     */
    public function index(): array
    {
        $own = ProviderCredential::query()->get()->keyBy('provider');

        return ['data' => array_values(array_map(function (string $provider) use ($own) {
            $platform = config("hr-avatar.platform_keys.{$provider}");

            return [
                'provider' => $provider,
                'source' => match (true) {
                    $own->has($provider) => CredentialSource::Account,
                    is_string($platform) && $platform !== '' => CredentialSource::Platform,
                    default => CredentialSource::None,
                },
                'last_four' => $own->get($provider)?->last_four,
            ];
        }, config()->array('hr-avatar.credential_providers')))];
    }

    /**
     * Store a provider credential
     *
     * Creates or replaces the account's key for the provider. The key is encrypted at rest and is
     * never returned; the answer shows only its last four characters.
     */
    public function update(PutCredentialRequest $request, string $provider): JsonResponse
    {
        $key = $request->apiKey();

        // 200 whether the key was created or replaced; a resource would answer 201 for a new row.
        return (new ProviderCredentialResource(ProviderCredential::query()->updateOrCreate(
            ['provider' => $provider],
            ['api_key' => $key, 'last_four' => LastFour::of($key)],
        )))->response()->setStatusCode(200);
    }

    /**
     * Remove a provider credential
     *
     * Idempotent: removing a key that is not stored answers 204 as well. Calls then use the
     * platform key, if one is set.
     */
    public function destroy(string $provider): Response
    {
        ProviderCredential::query()->where('provider', $provider)->delete();

        return response()->noContent();
    }
}
