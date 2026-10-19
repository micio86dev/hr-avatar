<?php

namespace App\Http\Resources;

use App\Models\ApiKey;
use Illuminate\Http\Request;

/**
 * An API key as the management endpoints show it. The member list is explicit: neither the
 * hash nor the key itself can reach a response by being added to the model.
 *
 * @mixin ApiKey
 */
class ApiKeyResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'mode' => $this->mode,
            'key_prefix' => $this->key_prefix,
            'scopes' => $this->scopes,
            'allowed_origins' => $this->allowed_origins,
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'revoked_at' => $this->revoked_at?->toIso8601ZuluString(),
            'last_used_at' => $this->last_used_at?->toIso8601ZuluString(),
            'rotated_from_id' => $this->rotated_from_id,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
