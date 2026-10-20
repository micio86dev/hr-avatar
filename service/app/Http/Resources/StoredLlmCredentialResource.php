<?php

namespace App\Http\Resources;

use App\Models\LlmCredential;
use Illuminate\Http\Request;

/**
 * The answer to a language-model credential write: the list entry plus when it was stored.
 *
 * @mixin LlmCredential
 */
class StoredLlmCredentialResource extends LlmCredentialResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'updated_at' => $this->updated_at?->toIso8601ZuluString()];
    }
}
