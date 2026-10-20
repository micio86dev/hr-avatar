<?php

namespace App\AvatarProviders;

/**
 * What a provider sync came to: the provider-side objects now match the config (`synced`), or the
 * provider needs none for it (`not_required`). A failure is an exception, never a result.
 */
final readonly class SyncResult
{
    private function __construct(public SyncStatus $status) {}

    public static function synced(): self
    {
        return new self(SyncStatus::Synced);
    }

    public static function notRequired(): self
    {
        return new self(SyncStatus::NotRequired);
    }
}
