<?php

namespace App\AvatarProviders\LiveAvatar;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ResourceKind;
use App\Credentials\ProviderAuth;
use App\Models\ProviderResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The `provider_resources` ledger as the two LiveAvatar registrars use it: objects created inside
 * LiveAvatar, found again by account, provider key and natural key instead of being created twice
 * (LiveAvatar does not deduplicate: three identical creates give three objects). Every method
 * binds the account itself, so a caller holding only an account id cannot read another's rows.
 */
final class LiveAvatarLedger
{
    public function __construct(private readonly LiveAvatarApi $api) {}

    public function find(string $accountId, ProviderAuth $auth, ResourceKind $kind, string $naturalKey): ?string
    {
        $id = CurrentAccount::runAs($accountId, fn () => $this->entry($auth, $kind, $naturalKey)->value('external_id'));

        return is_string($id) ? $id : null;
    }

    /**
     * Records an object, unless another writer recorded the same natural key first; the id that is
     * in the ledger afterwards comes back, so a caller whose own id lost can remove its duplicate.
     */
    public function record(string $accountId, ProviderAuth $auth, ResourceKind $kind, string $naturalKey, string $externalId): string
    {
        return CurrentAccount::runAs($accountId, function () use ($auth, $kind, $naturalKey, $externalId): string {
            try {
                // A nested transaction is a savepoint: a unique violation must not poison an outer one.
                DB::transaction(fn () => ProviderResource::query()->create([
                    'provider' => LiveAvatarApi::PROVIDER_ID,
                    'key_fingerprint' => $auth->fingerprint,
                    'kind' => $kind,
                    'natural_key' => $naturalKey,
                    'external_id' => $externalId,
                ]));

                return $externalId;
            } catch (UniqueConstraintViolationException) {
                $winner = $this->entry($auth, $kind, $naturalKey)->value('external_id');

                return is_string($winner) ? $winner : $externalId;
            }
        });
    }

    public function forget(string $accountId, ProviderAuth $auth, ResourceKind $kind, string $naturalKey): void
    {
        CurrentAccount::runAs($accountId, fn () => $this->entry($auth, $kind, $naturalKey)->delete());
    }

    /**
     * The id of the LiveAvatar secret holding `$value`, created on first use. LiveAvatar secrets
     * are immutable and their names are not unique, so the ledger is the only way to find one
     * again; a secret created by a writer that lost a race is removed (best effort).
     *
     * @throws ProviderException when the secret cannot be created
     */
    public function secret(
        string $accountId,
        ProviderAuth $auth,
        ResourceKind $kind,
        string $naturalKey,
        string $type,
        string $name,
        #[\SensitiveParameter] string $value,
    ): string {
        $known = $this->find($accountId, $auth, $kind, $naturalKey);

        if ($known !== null) {
            return $known;
        }

        $created = $this->api->stringAt(
            $this->api->orFail($this->api->call($auth, 'POST', '/secrets', ['secret_type' => $type, 'secret_value' => $value, 'secret_name' => $name])),
            'data.id',
        ) ?? throw ProviderException::noResponse();

        $recorded = $this->record($accountId, $auth, $kind, $naturalKey, $created);

        if ($recorded !== $created) {
            $this->removeQuietly($auth, '/secrets/'.rawurlencode($created));
        }

        return $recorded;
    }

    /** Deletes an object this service created and no longer needs. Never throws: a leftover is only clutter. */
    public function removeQuietly(ProviderAuth $auth, string $path): void
    {
        try {
            $this->api->call($auth, 'DELETE', $path);
        } catch (ProviderException) {
            // Nothing to do: the object stays in the provider until someone removes it.
        }
    }

    /**
     * @return Builder<ProviderResource>
     */
    private function entry(ProviderAuth $auth, ResourceKind $kind, string $naturalKey): Builder
    {
        return ProviderResource::query()
            ->where('provider', LiveAvatarApi::PROVIDER_ID)
            ->where('key_fingerprint', $auth->fingerprint)
            ->where('kind', $kind->value)
            ->where('natural_key', $naturalKey);
    }
}
