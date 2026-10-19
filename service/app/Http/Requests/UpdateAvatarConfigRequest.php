<?php

namespace App\Http\Requests;

use App\Models\AvatarConfig;

/**
 * A partial update: each member sent replaces the stored one as a whole (`config` is replaced, not
 * merged), each member left out is kept. The provider is fixed at creation; sending the one it
 * already has is allowed, any other is refused.
 */
class UpdateAvatarConfigRequest extends AvatarConfigRequest
{
    protected function presence(): string
    {
        return 'sometimes';
    }

    protected function providerRules(): array
    {
        return ['sometimes', 'string', function (string $attribute, mixed $value, callable $fail): void {
            if ($value !== $this->current()->provider) {
                $fail('The provider cannot be changed after creation.');
            }
        }];
    }

    /**
     * The members to change, as validated (never the provider, which cannot change).
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return array_intersect_key($this->validated(), array_flip(['name', 'config', 'persona', 'llm_model_id', 'voice', 'external_ref']));
    }

    protected function providerId(): string
    {
        return $this->current()->provider;
    }

    protected function existing(): ?AvatarConfig
    {
        return $this->current();
    }

    private function current(): AvatarConfig
    {
        /** @var AvatarConfig */
        return $this->route('avatarConfig');
    }
}
