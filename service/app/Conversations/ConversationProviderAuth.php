<?php

namespace App\Conversations;

use App\AvatarProviders\ProviderRegistry;
use App\Credentials\CredentialResolver;
use App\Credentials\ProviderAuth;
use App\Models\Conversation;

/**
 * The credential to call a conversation's provider with, resolved where the call is made: a provider
 * that takes none gets a placeholder, any other the account's key, else the platform's, else null.
 */
final class ConversationProviderAuth
{
    public function __construct(private readonly ProviderRegistry $providers, private readonly CredentialResolver $credentials) {}

    public function for(Conversation $conversation): ?ProviderAuth
    {
        if (! $this->providers->requiresCredential($conversation->provider)) {
            return ProviderAuth::none();
        }

        return $this->credentials->for($conversation->account_id, $conversation->provider);
    }
}
