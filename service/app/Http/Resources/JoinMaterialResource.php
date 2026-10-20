<?php

namespace App\Http\Resources;

use App\AvatarProviders\JoinMaterial;
use Illuminate\Http\Request;

/**
 * What the browser needs to join a provider session, and nothing server-side: no key and no reference
 * to the session. `transport` names the browser-side transport that can join it.
 *
 * @property-read JoinMaterial $resource
 */
class JoinMaterialResource extends ApiResource
{
    /**
     * @return array{provider: string, transport: string, conversation_url: string|null, session_token: string|null, expires_at: string}
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
