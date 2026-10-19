<?php

namespace App\Conversations;

/**
 * `pending` until the transcript has been reconciled with the provider's, then `ready`, or
 * `partial` when the provider's side never became available.
 */
enum TranscriptStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Partial = 'partial';
}
