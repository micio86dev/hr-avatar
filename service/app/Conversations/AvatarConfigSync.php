<?php

namespace App\Conversations;

use App\AvatarProviders\SyncStatus;
use App\Jobs\SyncAvatarConfig;
use App\Models\AvatarConfig;

/**
 * The one rule for when a config is sent to be synchronized: when it is waiting for one.
 */
final class AvatarConfigSync
{
    public static function dispatchWhenPending(AvatarConfig $config): void
    {
        if ($config->sync_status === SyncStatus::Pending) {
            SyncAvatarConfig::dispatch($config->account_id, $config->id);
        }
    }
}
