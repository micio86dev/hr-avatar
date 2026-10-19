<?php

namespace App\Webhooks\Outbound;

/**
 * The events an endpoint can subscribe to. The values are the `type` of the delivered body.
 */
enum EventType: string
{
    case ConversationLive = 'conversation.live';
    case ConversationEnded = 'conversation.ended';
    case TranscriptReady = 'transcript.ready';

    /**
     * Every event, in the order above: what an endpoint subscribes to when it names none.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
