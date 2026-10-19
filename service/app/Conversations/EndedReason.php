<?php

namespace App\Conversations;

/**
 * Why a conversation reached a terminal state. The first writer wins and the value never changes.
 */
enum EndedReason: string
{
    case Client = 'client';
    case Api = 'api';
    case MaxDuration = 'max_duration';
    case Provider = 'provider';
    case Expired = 'expired';
    case ProviderError = 'provider_error';
    case ProviderCapacity = 'provider_capacity';
}
