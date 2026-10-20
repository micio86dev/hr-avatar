<?php

namespace App\Conversations;

use App\Http\ApiProblem;
use App\Http\ErrorCode;

/**
 * A transition the state machine does not have, or one out of a terminal state. Raised for a request-driven
 * change it answers 409 `invalid_state`; a job that acts on a stale event catches it and drops the event.
 */
final class IllegalTransition extends ApiProblem
{
    public function __construct(public readonly ConversationStatus $from, public readonly ConversationStatus $to)
    {
        parent::__construct(ErrorCode::InvalidState);
    }
}
