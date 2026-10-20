<?php

namespace App\Http\Resources;

use App\Models\LlmModel;
use Illuminate\Http\Request;

/**
 * A catalogue entry. Explicit members: nothing internal can reach a response by being added to the model.
 *
 * @mixin LlmModel
 */
class LlmModelResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'model' => $this->model,
            'display_name' => $this->display_name,
            'base_url' => $this->base_url,
            'enabled' => $this->enabled,
        ];
    }
}
