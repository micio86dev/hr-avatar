<?php

namespace App\Conversations;

use App\AvatarProviders\SyncStatus;
use App\Jobs\SyncAvatarConfig;
use App\Models\AvatarConfig;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * The one rule for when a config is sent to be synchronized: when it is waiting for one.
 *
 * The config is saved before its job is pushed, so a push that fails (the queue is down) would leave a
 * config `pending` that nothing ever syncs. It is recorded as `failed` with a fixed reason instead, on the
 * revision that was to be synced, and the request that made the change still succeeds: the caller sees the
 * state and retries with `POST /v1/avatar-configs/{id}/sync`.
 *
 * A synchronous queue (local development, tests) runs the job inside the push, so a failure there is the
 * job's, not the queue's: it has recorded its own reason, and the exception is left to reach the caller.
 */
final class AvatarConfigSync
{
    public const NOT_QUEUED = 'Synchronization could not be queued.';

    public static function dispatchWhenPending(AvatarConfig $config): void
    {
        if ($config->sync_status !== SyncStatus::Pending) {
            return;
        }

        if (Queue::connection() instanceof SyncQueue) {
            SyncAvatarConfig::dispatch($config->account_id, $config->id, $config->sync_revision);

            return;
        }

        try {
            SyncAvatarConfig::dispatch($config->account_id, $config->id, $config->sync_revision);
        } catch (Throwable $failure) {
            // Only the kind of failure: a connection error may name the queue's address.
            Log::error('Avatar config sync could not be queued', ['config_id' => $config->id, 'failure' => $failure::class]);

            AvatarConfig::query()->where('account_id', $config->account_id)->whereKey($config->id)->where('sync_revision', $config->sync_revision)
                ->update(['sync_status' => SyncStatus::Failed->value, 'sync_error' => self::NOT_QUEUED]);
        }
    }
}
