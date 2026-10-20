<?php

namespace App\Credentials;

use App\Accounts\CurrentAccount;
use App\Models\LlmCredential;
use App\Models\LlmModel;

/**
 * The account's key for a catalogue model's language-model provider. There is no platform
 * fallback: a language model is always paid for with the account's own key.
 */
final class LlmCredentialResolver
{
    public function for(string $accountId, LlmModel $model): ?string
    {
        $credential = CurrentAccount::runAs($accountId, fn () => LlmCredential::query()->where('llm_provider', $model->provider)->first());

        return $credential?->api_key;
    }
}
