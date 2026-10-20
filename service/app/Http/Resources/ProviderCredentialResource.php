<?php

namespace App\Http\Resources;

use App\Credentials\CredentialSource;
use App\Models\ProviderCredential;
use Illuminate\Http\Request;

/**
 * The answer to a credential write. The key itself is not a member and cannot become one by
 * being added to the model.
 *
 * @mixin ProviderCredential
 */
class ProviderCredentialResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'provider' => $this->provider,
            'source' => CredentialSource::Account,
            'last_four' => $this->last_four,
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
