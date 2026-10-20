<?php

namespace App\AvatarProviders;

/**
 * Why a provider call failed, in the three ways the service reacts differently: the provider is
 * at capacity, the provider refused a request this service made, or the provider failed.
 */
enum FailureClass: string
{
    case Throttle = 'throttle';
    case Upstream = 'upstream';
    case ClientError = 'client_error';
}
