<?php

namespace App\Credentials;

use RuntimeException;

/**
 * A job found no credential for a provider that needs one. Waiting does not produce a key, so a job
 * that meets this fails itself instead of spending its retries.
 */
final class CredentialMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No credential is available for the provider.');
    }
}
