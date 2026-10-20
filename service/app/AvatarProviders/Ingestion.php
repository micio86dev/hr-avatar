<?php

namespace App\AvatarProviders;

/**
 * How a provider's events reach the service, so no code outside the adapters needs to know a
 * provider's name: the provider calls back, the service polls it, or there is nothing to receive.
 */
enum Ingestion: string
{
    case Callback = 'callback';
    case Poll = 'poll';
    case None = 'none';
}
