<?php

namespace App\Http\Controllers\V1;

use App\Http\Resources\LlmModelResource;
use App\Models\LlmModel;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The platform's language-model catalogue, read-only. Requires the `llm_models:read` scope.
 */
#[Group('Credentials')]
class LlmModelController
{
    /**
     * List language models
     *
     * The enabled entries of the platform catalogue. The catalogue is the same for every account
     * and cannot be changed through the API.
     */
    public function index(): AnonymousResourceCollection
    {
        return LlmModelResource::collection(LlmModel::query()->where('enabled', true)->orderBy('provider')->orderBy('id')->get());
    }
}
