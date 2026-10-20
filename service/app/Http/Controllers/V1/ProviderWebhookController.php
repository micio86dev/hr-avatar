<?php

namespace App\Http\Controllers\V1;

use App\AvatarProviders\MalformedCallback;
use App\AvatarProviders\ProviderRegistry;
use App\Conversations\ProviderEventIngestor;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Http\Middleware\AuthenticateProviderCallback;
use App\Http\Problem;
use App\Http\Requests\ProviderCallbackRequest;
use App\Models\Conversation;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;

/**
 * Where a provider that reports by callback tells the service what happened. There is no key: the token in
 * the path is the credential, one per conversation, checked by `AuthenticateProviderCallback` before this runs.
 *
 * Nothing here knows a provider by name: the adapter of the authenticated conversation reads its own callback.
 */
#[Group('Provider webhooks')]
class ProviderWebhookController
{
    /**
     * Receive a provider callback
     *
     * Authenticated by the unguessable token in the path alone (a provider cannot send a key). The event is
     * stored first, so it is not lost, and is processed afterwards; a callback seen before is answered the
     * same and changes nothing. A token, provider or conversation id that does not match is one identical
     * 404: the answer does not say which part was wrong. A body above 256 KB is refused before anything else.
     *
     * @return array{received: bool}
     */
    #[ResponseDoc(404, description: 'The token, the provider or the conversation id does not match (`not_found`); the answer is the same for each.', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    #[ResponseDoc(413, description: 'The body is above 256 KB (`payload_too_large`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    #[ResponseDoc(422, description: 'The token is good but the body is not a callback: it must be a JSON object with `conversation_id`, `event_type` and `timestamp`, and the provider\'s adapter must be able to read it (`validation_failed`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    #[ResponseDoc(429, description: 'More than 120 callbacks a minute from one address (`rate_limited`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    public function receive(ProviderCallbackRequest $request, ProviderRegistry $providers, ProviderEventIngestor $ingestor): array
    {
        /** @var Conversation $conversation */
        $conversation = $request->attributes->get(AuthenticateProviderCallback::CONVERSATION_ATTRIBUTE);
        $payload = $request->payload();

        if ($payload['conversation_id'] !== $conversation->provider_session_ref) {
            throw new ApiProblem(ErrorCode::NotFound);
        }

        try {
            $events = $providers->get($conversation->provider)->parseCallback($payload);
        } catch (MalformedCallback) {
            // Nothing of the adapter's message is returned: it may quote the provider's body.
            throw new ApiProblem(ErrorCode::ValidationFailed);
        }

        $ingestor->ingest($conversation, $events);

        return ['received' => true];
    }
}
