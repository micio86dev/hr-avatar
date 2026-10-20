<?php

namespace App\Http\Resources;

use App\AvatarProviders\SyncStatus;
use App\Models\AvatarConfig;
use Illuminate\Http\Request;
use stdClass;

/**
 * An avatar config as the API shows it. The member list is explicit: the adapter's own
 * `provider_state`, the owner and the deletion time can never reach a response by being added to
 * the model. The settings objects are cast to objects so that an empty one is written as `{}`, not
 * `[]` (PHP cannot tell the two apart).
 *
 * @mixin AvatarConfig
 */
class AvatarConfigResource extends ApiResource
{
    /**
     * @return array{id: string, name: string, provider: string, config: array<string, mixed>|stdClass, persona: array<string, mixed>|stdClass|null, llm_model_id: string|null, voice: array<string, mixed>|stdClass|null, external_ref: string|null, sync_status: SyncStatus, sync_error: string|null, synced_at: string|null, created_at: string|null, updated_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider,
            'config' => $this->settings($this->config),
            'persona' => $this->persona === null ? null : $this->settings($this->persona),
            'llm_model_id' => $this->llm_model_id,
            'voice' => $this->voice === null ? null : $this->settings($this->voice),
            'external_ref' => $this->external_ref,
            'sync_status' => $this->sync_status,
            'sync_error' => $this->sync_error,
            'synced_at' => $this->synced_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>|stdClass
     */
    private function settings(array $value): array|stdClass
    {
        return $value === [] ? new stdClass : $value;
    }
}
