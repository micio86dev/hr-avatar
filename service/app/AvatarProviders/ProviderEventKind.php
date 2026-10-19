<?php

namespace App\AvatarProviders;

enum ProviderEventKind: string
{
    case Joined = 'joined';
    case Ended = 'ended';
    case TranscriptReady = 'transcript_ready';
    case Ignored = 'ignored';
}
