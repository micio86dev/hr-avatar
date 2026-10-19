<?php

namespace App\Http\Controllers\V1;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ProviderRegistry;
use App\Credentials\CredentialResolver;
use App\Credentials\ProviderAuth;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Http\Problem;
use App\Http\Requests\CatalogueRequest;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * What can be configured per provider, and what each provider offers to pick from. Requires the
 * `avatar_configs:read` scope.
 */
#[Group('Catalogue')]
class CatalogueController
{
    /** Short enough that an avatar or voice created at the provider shows up soon; `fresh=1` skips the wait. */
    private const CACHE_SECONDS = 300;

    public function __construct(private readonly ProviderRegistry $providers, private readonly CredentialResolver $credentials) {}

    /**
     * Describe the providers
     *
     * For every provider: the settings its avatar configs accept (`fields` for `config`,
     * `persona_fields` for `persona`: key, type, whether required, limits and allowed values), the
     * resources it can list, and the voice engines it speaks with. Makes no provider call.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function index(): array
    {
        return ['data' => array_map(
            fn (string $id) => ['provider' => $id, ...$this->providers->get($id)->describe()->toArray()],
            $this->providers->ids(),
        )];
    }

    /**
     * List a provider's inventory
     *
     * Fetches what the account can pick from at the provider (for example avatars, voices, replicas
     * or personas) with the account's credential, else the platform's, and returns normalized
     * entries. `language` is set only when the provider states it. The result is cached for five
     * minutes per credential; `fresh=1` skips the cache. The provider's raw answer, errors and keys
     * are never returned.
     *
     * @return array{data: list<array{id: string, name: string, language: string|null, preview_audio_url: string|null, preview_audio_via_api: bool, preview_image_url: string|null, preview_video_url: string|null}>}
     */
    #[ResponseDoc(404, description: 'The provider does not list that resource.', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    #[ResponseDoc(422, description: 'No credential is available for the provider (`provider_credential_missing`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    #[ResponseDoc(502, description: 'The provider failed or could not be reached (`provider_error`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    public function show(CatalogueRequest $request, string $provider, string $resource): array
    {
        // An unknown pair is a 404 before any credential is looked up, so it reveals nothing about them.
        if (! in_array($provider, $this->providers->ids(), true)
            || ! in_array($resource, $this->providers->get($provider)->describe()->resources, true)) {
            throw new ApiProblem(ErrorCode::NotFound);
        }

        $auth = $this->credentials->for(app(CurrentAccount::class)->require(), $provider)
            ?? throw new ApiProblem(ErrorCode::ProviderCredentialMissing);

        return ['data' => $this->entries($provider, $resource, $auth, $request->fresh())];
    }

    /**
     * @return list<array{id: string, name: string, language: string|null, preview_audio_url: string|null, preview_audio_via_api: bool, preview_image_url: string|null, preview_video_url: string|null}>
     */
    private function entries(string $provider, string $resource, ProviderAuth $auth, bool $fresh): array
    {
        // The key's owner and fingerprint make two accounts with different keys two cache entries; a
        // key replaced since is a new entry. The platform key is one entry shared by every account that uses it.
        $key = "hra:catalogue:{$auth->owner}:{$auth->fingerprint}:{$provider}:{$resource}";

        if ($fresh) {
            Cache::forget($key);
        }

        try {
            return Cache::remember($key, self::CACHE_SECONDS, fn () => array_map(
                fn ($entry) => $entry->toArray(),
                $this->providers->get($provider)->catalogue($resource, $auth),
            ));
        } catch (ProviderException $failure) {
            // Always a 502: a provider's own 429 is not this service's concurrency limit.
            Log::warning('Provider catalogue request failed', $failure->context());

            throw new ApiProblem(ErrorCode::ProviderError);
        }
    }
}
