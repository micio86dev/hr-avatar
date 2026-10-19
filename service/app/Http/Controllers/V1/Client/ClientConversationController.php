<?php

namespace App\Http\Controllers\V1\Client;

use App\Conversations\ConnectConversation;
use App\Http\Problem;
use App\Http\Resources\JoinMaterialResource;
use App\Models\Conversation;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;

/**
 * A conversation, from the browser that holds it: authenticated with a publishable key and the
 * conversation's own client secret, never with a secret key.
 */
#[Group('Client')]
class ClientConversationController
{
    /**
     * Connect
     *
     * Starts the provider session for a conversation that has not been connected, and answers with what the
     * browser needs to join it. It can succeed once per conversation: a second call is `already_connected`.
     * The provider's and the language model's credentials are checked first; a missing one is a 422 that leaves
     * the conversation as it was. If the provider cannot start the session the conversation fails and the
     * answer is a 502 (a 429 when the provider is at capacity).
     */
    #[ResponseDoc(409, description: 'The conversation is already connected (`already_connected`), or is over (`invalid_state`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    #[ResponseDoc(422, description: 'The provider has no credential (`provider_credential_missing`) or the language model has none (`llm_credential_missing`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    #[ResponseDoc(429, description: 'The provider is at its concurrency limit (`provider_concurrency_limited`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    #[ResponseDoc(502, description: 'The provider could not start the session (`provider_error`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    public function connect(Conversation $conversation, ConnectConversation $connect): JoinMaterialResource
    {
        return new JoinMaterialResource($connect->connect($conversation));
    }
}
