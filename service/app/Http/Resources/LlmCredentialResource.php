<?php

namespace App\Http\Resources;

use App\Models\LlmCredential;
use Illuminate\Http\Request;

/**
 * A stored language-model credential as the list shows it: the provider and the last four
 * characters of the key, never the key.
 *
 * @mixin LlmCredential
 */
class LlmCredentialResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'llm_provider' => $this->llm_provider,
            'last_four' => $this->last_four,
        ];
    }
}
