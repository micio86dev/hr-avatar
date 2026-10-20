<?php

namespace App\Http\Controllers\V1;

use App\Conversations\ConversationStatus;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Http\Problem;
use App\Http\Resources\TranscriptResource;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;

/**
 * A conversation's transcript, for the host's back end. Requires the `conversations:read` scope.
 */
#[Group('Conversations')]
class TranscriptController
{
    /**
     * Read a transcript
     *
     * What was said in a conversation that has been connected, earliest first (by the time the browser or
     * the provider gave it, then by arrival; lines with no time come last). `status` is `pending` until the
     * provider's own transcript has been merged in, then `ready`, or `partial` when the provider's side could
     * not be had and only what the browser forwarded is here. A conversation that was never connected has no
     * transcript: 409 `invalid_state`. The answer is not paginated: it is bounded where events are written (a
     * conversation takes at most 10,000 events from the browser, and the provider's transcript is bounded by the
     * conversation's duration).
     */
    #[ResponseDoc(409, description: 'The conversation was never connected (`invalid_state`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    public function show(Conversation $conversation): TranscriptResource
    {
        if ($conversation->status === ConversationStatus::Created) {
            throw new ApiProblem(ErrorCode::InvalidState);
        }

        $utterances = ConversationEvent::query()
            ->where('conversation_id', $conversation->id)
            ->where('type', 'utterance')
            ->orderByRaw('occurred_at asc nulls last')
            ->orderBy('sequence')
            ->get();

        return new TranscriptResource($conversation, $utterances);
    }
}
