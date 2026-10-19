<?php

namespace App\Jobs;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ProviderRegistry;
use App\AvatarProviders\SyncBlocked;
use App\AvatarProviders\SyncStatus;
use App\Credentials\CredentialResolver;
use App\Models\AvatarConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Brings an avatar config's provider-side objects in line with the config. Idempotent: the adapter
 * creates what is missing and updates what exists. It carries ids only (a provider key never sits
 * in the queue) and resolves the credential where it runs.
 *
 * Three attempts, 30 and 120 seconds apart. A sync that is blocked (a credential is missing) cannot
 * succeed by repeating, so it fails at once. The final failure is recorded on the config with a
 * message from a closed set: a provider failure's generic sentence, a blocked sync's fixed sentence,
 * or "Synchronization failed." Nothing a provider or an exception wrote is ever stored.
 *
 * The job carries the config's `sync_revision` at the time it was queued, and the revision moves up
 * with every change to what is synchronized. A run may settle the config (synced, not required or
 * failed) only while the row still holds that revision: the write is a conditional UPDATE, so a run
 * overtaken by a newer change is a no-op and the newer state keeps waiting for its own run. A run that
 * finds the config already past its revision when it starts makes no provider call at all.
 *
 * Known limit: a run already past that check can still send the older state to the provider while a
 * newer run does the same; the config then stays honest (the older run does not settle it) but the
 * provider-side objects hold whichever call landed last until the next sync.
 */
final class SyncAvatarConfig implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $accountId, public readonly string $avatarConfigId, public readonly int $revision = 0) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ProviderRegistry $providers, CredentialResolver $credentials): void
    {
        CurrentAccount::runAs($this->accountId, function () use ($providers, $credentials): void {
            $config = AvatarConfig::query()->find($this->avatarConfigId);

            if ($config === null || $config->sync_revision !== $this->revision) {
                return;
            }

            // A provider that takes no credential has nothing to create for any config.
            if (! $providers->requiresCredential($config->provider)) {
                $this->settle(SyncStatus::NotRequired);

                return;
            }

            try {
                $auth = $credentials->for($config->account_id, $config->provider) ?? throw SyncBlocked::providerCredentialMissing();

                $this->settle($providers->get($config->provider)->sync($config, $auth)->status);
            } catch (SyncBlocked $blocked) {
                $this->fail($blocked);
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        $this->write([
            'sync_status' => SyncStatus::Failed->value,
            'sync_error' => $exception instanceof ProviderException || $exception instanceof SyncBlocked ? $exception->getMessage() : 'Synchronization failed.',
        ]);
    }

    private function settle(SyncStatus $status): void
    {
        $this->write([
            'sync_status' => $status->value,
            'sync_error' => null,
            ...($status === SyncStatus::Synced ? ['synced_at' => now()] : []),
        ]);
    }

    /**
     * The one write: it lands only on the revision this run was queued for.
     *
     * @param  array<string, mixed>  $values
     */
    private function write(array $values): void
    {
        CurrentAccount::runAs($this->accountId, fn () => AvatarConfig::query()
            ->whereKey($this->avatarConfigId)
            ->where('sync_revision', $this->revision)
            ->update($values));
    }
}
