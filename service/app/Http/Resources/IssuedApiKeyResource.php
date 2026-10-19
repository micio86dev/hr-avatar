<?php

namespace App\Http\Resources;

use App\Models\ApiKey;
use Illuminate\Http\Request;

/**
 * The create and rotate response: the key resource plus the full `key`, shown this once. It is
 * a separate class so that no other response can carry the key by accident.
 *
 * @mixin ApiKey
 */
class IssuedApiKeyResource extends ApiKeyResource
{
    public function __construct(ApiKey $key, private readonly string $rawKey)
    {
        parent::__construct($key);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'key' => $this->rawKey];
    }
}
