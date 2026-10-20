<?php

namespace App\Webhooks\Outbound;

/**
 * Where a delivery is: `pending` until an attempt gets a 2xx (and while attempts remain), then
 * `succeeded`, or `failed` once the last attempt has failed or the delivery was stopped.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
