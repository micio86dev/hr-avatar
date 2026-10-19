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
 * Known limit: the config is read once. A PATCH during the run queues its own job, but if this run
 * finishes after that job it can mark the config synced for the state it read; the next change or a
 * manual sync corrects it.
 */
final class SyncAvatarConfig implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $accountId, public readonly string $avatarConfigId) {}

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

            if ($config === null) {
                return;
            }

            // A provider that takes no credential has nothing to create for any config.
            if (! $providers->requiresCredential($config->provider)) {
                $this->settle($config, SyncStatus::NotRequired);

                return;
            }

            try {
                $auth = $credentials->for($config->account_id, $config->provider) ?? throw SyncBlocked::providerCredentialMissing();

                $this->settle($config, $providers->get($config->provider)->sync($config, $auth)->status);
            } catch (SyncBlocked $blocked) {
                $this->fail($blocked);
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        CurrentAccount::runAs($this->accountId, function () use ($exception): void {
            $config = AvatarConfig::query()->find($this->avatarConfigId);

            $config?->forceFill([
                'sync_status' => SyncStatus::Failed,
                'sync_error' => $exception instanceof ProviderException || $exception instanceof SyncBlocked ? $exception->getMessage() : 'Synchronization failed.',
            ])->saveQuietly();
        });
    }

    private function settle(AvatarConfig $config, SyncStatus $status): void
    {
        $config->forceFill([
            'sync_status' => $status,
            'sync_error' => null,
            'synced_at' => $status === SyncStatus::Synced ? now() : $config->synced_at,
        ])->saveQuietly();
    }
}
