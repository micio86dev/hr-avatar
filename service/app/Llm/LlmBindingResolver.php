<?php

namespace App\Llm;

use App\Credentials\LlmCredentialResolver;
use App\Models\AvatarConfig;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Turns a config's bound model and the account's key into an LlmBinding. Never throws: it
 * returns null for a config with no model, for a model that no longer resolves, and for an
 * account with no usable key. Whoever needs to tell "unbound" from "key missing" checks
 * `llm_model_id` first (connect does, to answer `llm_credential_missing`).
 *
 * A stored key that cannot be decrypted counts as missing: there is no platform fallback to
 * bill by mistake, and the remedy is the same, to store the key again.
 */
final class LlmBindingResolver
{
    public function __construct(private readonly LlmCredentialResolver $credentials) {}

    public function resolve(AvatarConfig $config): ?LlmBinding
    {
        $model = $config->llm_model_id === null ? null : $config->llmModel;

        if ($model === null) {
            return null;
        }

        try {
            $key = $this->credentials->for($config->account_id, $model);
        } catch (DecryptException) {
            return null;
        }

        return $key === null ? null : new LlmBinding($model->model, $model->base_url, $key);
    }
}
