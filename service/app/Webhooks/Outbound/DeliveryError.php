<?php

namespace App\Webhooks\Outbound;

/**
 * Why a delivery attempt failed: the closed set of `last_error` codes, stored as a short string (the column is a
 * `varchar`, so adding a code needs no migration). The deliveries endpoint documents the same list, and a test
 * fails when the two differ. A receiver's refusal is the one code that is not a case: `http_<status>`.
 */
enum DeliveryError: string
{
    case Timeout = 'timeout';
    case ConnectionFailed = 'connection_failed';
    /** The destination is not a public address (the outbound host guard refused it). */
    case DestinationNotAllowed = 'destination_not_allowed';
    /** The host name gave no address to judge, so nothing was sent. */
    case DestinationUnresolved = 'destination_unresolved';
    /** The endpoint was disabled or deleted before the attempt. */
    case EndpointDisabled = 'endpoint_disabled';
    /** The job died before it recorded its attempt. */
    case InternalError = 'internal_error';

    /** The code of an attempt the receiver answered with a status that is not a success. */
    public static function http(int $status): string
    {
        return "http_{$status}";
    }
}
