<?php

namespace App\Credentials;

/**
 * Whose key a provider call would be made with, as the credential list reports it.
 */
enum CredentialSource: string
{
    case Account = 'account';
    case Platform = 'platform';
    case None = 'none';
}
