<?php

namespace App\Conversations;

/**
 * Where a conversation is: `created -> connecting -> live -> ended | failed`. The transitions
 * themselves are the lifecycle's business; this only names the states.
 */
enum ConversationStatus: string
{
    case Created = 'created';
    case Connecting = 'connecting';
    case Live = 'live';
    case Ended = 'ended';
    case Failed = 'failed';

    /**
     * The states a conversation can still leave: everything but the terminal ones.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => ! $status->isTerminal()));
    }

    /** `ended` and `failed` are final: nothing changes after them. */
    public function isTerminal(): bool
    {
        return $this === self::Ended || $this === self::Failed;
    }
}
