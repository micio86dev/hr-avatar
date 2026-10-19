<?php

namespace App\Http\Resources;

use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptStatus;
use App\Models\Conversation;
use Illuminate\Http\Request;
use stdClass;

/**
 * A conversation as the API shows it. The member list is explicit: the provider references, the
 * callback token hash, the prompt and the owner can never reach a response by being added to the model.
 *
 * @mixin Conversation
 */
class ConversationResource extends ApiResource
{
    /**
     * @return array{id: string, status: ConversationStatus, avatar_config_id: string, provider: string, language: string, max_duration_seconds: int, external_ref: string|null, metadata: stdClass, ended_reason: EndedReason|null, transcript_status: TranscriptStatus, created_at: string, connecting_at: string|null, live_at: string|null, ended_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'avatar_config_id' => $this->avatar_config_id,
            'provider' => $this->provider,
            'language' => $this->language,
            'max_duration_seconds' => $this->max_duration_seconds,
            'external_ref' => $this->external_ref,
            'metadata' => $this->metadata,
            'ended_reason' => $this->ended_reason,
            'transcript_status' => $this->transcript_status,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'connecting_at' => $this->connecting_at?->toIso8601ZuluString(),
            'live_at' => $this->live_at?->toIso8601ZuluString(),
            'ended_at' => $this->ended_at?->toIso8601ZuluString(),
        ];
    }
}
