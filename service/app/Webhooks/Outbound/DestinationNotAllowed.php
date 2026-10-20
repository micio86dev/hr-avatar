<?php

namespace App\Webhooks\Outbound;

use RuntimeException;

/**
 * A webhook destination that must not be connected to. The message is fixed: it never repeats the
 * URL or the address, which are the host's and could be read back from a log.
 */
final class DestinationNotAllowed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The destination is not allowed.');
    }
}
