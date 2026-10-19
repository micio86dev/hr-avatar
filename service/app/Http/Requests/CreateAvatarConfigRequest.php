<?php

namespace App\Http\Requests;

use App\AvatarProviders\ProviderRegistry;
use App\Models\AvatarConfig;
use Illuminate\Validation\Rule;

class CreateAvatarConfigRequest extends AvatarConfigRequest
{
    protected function presence(): string
    {
        return 'required';
    }

    protected function providerRules(): array
    {
        return ['required', 'string', Rule::in(app(ProviderRegistry::class)->ids())];
    }

    /**
     * The members of a new config, as validated. Nothing the client may not choose (the owner, the
     * id, the sync state) is among them.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return array_intersect_key($this->validated(), array_flip(['name', 'provider', 'config', 'persona', 'llm_model_id', 'voice', 'external_ref']));
    }

    protected function providerId(): string
    {
        return $this->string('provider')->toString();
    }

    protected function existing(): ?AvatarConfig
    {
        return null;
    }
}
