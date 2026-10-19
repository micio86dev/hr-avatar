<?php

namespace App\Http\Controllers\V1;

use App\Credentials\LastFour;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Http\Problem;
use App\Http\Requests\PutCredentialRequest;
use App\Http\Resources\LlmCredentialResource;
use App\Http\Resources\StoredLlmCredentialResource;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The account's keys for language-model providers of the catalogue. Write-only, and with no
 * platform fallback. Requires the `credentials:manage` scope.
 */
#[Group('Credentials')]
class LlmCredentialController
{
    /**
     * List language-model credentials
     *
     * The account's stored keys, shown as the provider and the last four characters of the key (null
     * for a key under 12 characters).
     */
    public function index(): AnonymousResourceCollection
    {
        return LlmCredentialResource::collection(LlmCredential::query()->orderBy('llm_provider')->get());
    }

    /**
     * Store a language-model credential
     *
     * Creates or replaces the account's key for a provider that appears in the model catalogue (404
     * otherwise). The key is encrypted at rest and never returned.
     */
    #[ResponseDoc(404, description: 'The provider does not appear in the model catalogue.', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    public function update(PutCredentialRequest $request, string $llmProvider): JsonResponse
    {
        if (! LlmModel::query()->where('provider', $llmProvider)->exists()) {
            throw new ApiProblem(ErrorCode::NotFound);
        }

        $key = $request->apiKey();

        // 200 whether the key was created or replaced; a resource would answer 201 for a new row.
        return (new StoredLlmCredentialResource(LlmCredential::query()->updateOrCreate(
            ['llm_provider' => $llmProvider],
            ['api_key' => $key, 'last_four' => LastFour::of($key)],
        )))->response()->setStatusCode(200);
    }

    /**
     * Remove a language-model credential
     *
     * Idempotent: answers 204 whether or not a key was stored. A conversation whose config binds a
     * model of this provider then fails to connect with `llm_credential_missing`.
     */
    public function destroy(string $llmProvider): Response
    {
        LlmCredential::query()->where('llm_provider', $llmProvider)->delete();

        return response()->noContent();
    }
}
