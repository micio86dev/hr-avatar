<?php

namespace App\Http\Resources;

use App\Conversations\TranscriptStatus;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A conversation's transcript: its status and its utterances, with nothing of the events' internals
 * (no key, no data) and none of the lifecycle signals.
 *
 * @property-read Conversation $resource
 */
class TranscriptResource extends ApiResource
{
    /**
     * @param  Collection<int, ConversationEvent>  $utterances  in the order to show them
     */
    public function __construct(Conversation $conversation, private readonly Collection $utterances)
    {
        parent::__construct($conversation);
    }

    /**
     * @return array{conversation_id: string, status: TranscriptStatus, utterances: list<array{sequence: int, role: string|null, text: string|null, occurred_at: string|null, source: string}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'conversation_id' => $this->resource->id,
            'status' => $this->resource->transcript_status,
            'utterances' => array_values($this->utterances->map(fn (ConversationEvent $event) => [
                'sequence' => $event->sequence,
                'role' => $event->role,
                'text' => $event->text,
                'occurred_at' => $event->occurred_at?->toIso8601ZuluString('millisecond'),
                'source' => $event->source,
            ])->all()),
        ];
    }
}
