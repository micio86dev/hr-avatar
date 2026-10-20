<?php

namespace App\Http\Resources;

use App\Keys\MintedClientSecret;
use App\Models\Conversation;
use Illuminate\Http\Request;

/**
 * The create response: the conversation plus the `client_secret` the browser connects with, shown
 * this once. A separate class, so that no other response can carry it by accident.
 *
 * @mixin Conversation
 */
class CreatedConversationResource extends ConversationResource
{
    public function __construct(Conversation $conversation, private readonly MintedClientSecret $secret)
    {
        parent::__construct($conversation);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'client_secret' => $this->secret->token,
            'client_secret_expires_at' => $this->secret->expiresAt->toIso8601ZuluString(),
        ];
    }
}
