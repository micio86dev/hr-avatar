<?php

namespace App\Http\Controllers\V1\Client;

use App\Conversations\ConnectConversation;
use App\Conversations\ConversationLifecycle;
use App\Conversations\EndedReason;
use App\Conversations\EventIngestor;
use App\Http\Problem;
use App\Http\Requests\ClientEventsRequest;
use App\Http\Resources\ConversationResource;
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

    /**
     * Send events
     *
     * Stores a batch of 1 to 100 events (utterances and lifecycle signals) in order, once each: a
     * key sent before is counted in `duplicates` and not stored again, so a batch can be retried safely. One
     * invalid event rejects the whole batch (422). Events are taken while the conversation is open and for a
     * minute after it ended; later ones are `conversation_closed`. An utterance before the conversation is
     * connected is `invalid_state`. A `live` signal makes a connecting conversation live.
     *
     * @return array{accepted: int, duplicates: int}
     */
    #[ResponseDoc(409, description: 'The conversation closed more than a minute ago (`conversation_closed`), or an utterance was sent before it was connected (`invalid_state`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    public function events(ClientEventsRequest $request, Conversation $conversation, EventIngestor $ingestor): array
    {
        return $ingestor->ingest($conversation, $request->events());
    }

    /**
     * End
     *
     * Ends the conversation from the browser, with the reason `client`, and answers 200 with it. Ending a
     * conversation that is already over answers 200 with it unchanged.
     */
    public function end(Conversation $conversation, ConversationLifecycle $lifecycle): ConversationResource
    {
        $lifecycle->end($conversation, EndedReason::Client);

        return new ConversationResource($conversation);
    }
}
