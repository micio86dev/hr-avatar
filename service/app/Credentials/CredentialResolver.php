<?php

namespace App\Credentials;

use App\Accounts\CurrentAccount;
use App\Models\ProviderCredential;

/**
 * Which key an account's provider call is made with: its own, else the platform's, else none.
 * One account's key is never used for another, and a provider that is not a configured
 * credential provider (the Mock adapter, for one) has no key to resolve.
 *
 * An account key that cannot be decrypted is not skipped: falling back to the platform key
 * would bill the platform for a call the account meant to pay for.
 */
final class CredentialResolver
{
    public function for(string $accountId, string $provider): ?ProviderAuth
    {
        if (! in_array($provider, config()->array('hr-avatar.credential_providers'), true)) {
            return null;
        }

        $own = CurrentAccount::runAs($accountId, fn () => ProviderCredential::query()->where('provider', $provider)->first());

        if ($own !== null) {
            return new ProviderAuth($own->api_key, $accountId);
        }

        $platform = config("hr-avatar.platform_keys.{$provider}");

        return is_string($platform) && $platform !== '' ? new ProviderAuth($platform, ProviderAuth::PLATFORM) : null;
    }
}
