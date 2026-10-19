<?php

namespace App\Jobs\Middleware;

use App\Jobs\SyncAvatarConfig;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Lets one run of a config's sync be in flight at a time, so two runs can never send the provider
 * different states at once.
 *
 * A run that finds another in flight does not start and is not released: a release would spend one of
 * its three attempts on a run that never began, and could fail a config whose own run never happened.
 * A fresh copy of the job is queued a little later instead, with the same ids and revision and a full
 * set of attempts of its own. The lock is keyed by account and config and expires on its own, so a worker
 * that dies mid-run cannot hold a config for longer than that.
 */
final class SerializeConfigSync
{
    /** Longer than any run (a handful of provider calls with their own timeouts); the bound on a dead worker. */
    private const LOCK_SECONDS = 300;

    /** How long a run that had to wait lets the other finish. */
    private const WAIT_SECONDS = 10;

    public function handle(SyncAvatarConfig $job, Closure $next): void
    {
        $lock = Cache::lock("hra:sync-config:{$job->accountId}:{$job->avatarConfigId}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            SyncAvatarConfig::dispatch($job->accountId, $job->avatarConfigId, $job->revision)->delay(self::WAIT_SECONDS);

            return;
        }

        try {
            $next($job);
        } finally {
            $lock->release();
        }
    }
}
