<?php

namespace App\AvatarProviders;

use RuntimeException;

/**
 * A sync that cannot start because something it needs is missing: no request was sent. Retrying
 * cannot help; the remedy is on the account (store the credential) or in the config. The message
 * is one of a closed set of fixed sentences, so it is safe to show to the account as `sync_error`.
 */
final class SyncBlocked extends RuntimeException
{
    public static function providerCredentialMissing(): self
    {
        return new self('The provider credential is missing.');
    }

    public static function llmCredentialMissing(): self
    {
        return new self('The language model credential is missing.');
    }

    public static function voiceCredentialMissing(): self
    {
        return new self('The voice vendor credential is missing.');
    }

    public static function incompleteConfig(): self
    {
        return new self('The avatar configuration is incomplete.');
    }
}
