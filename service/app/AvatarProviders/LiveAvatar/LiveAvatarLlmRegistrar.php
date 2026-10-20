<?php

namespace App\AvatarProviders\LiveAvatar;

use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ResourceKind;
use App\Credentials\ProviderAuth;
use App\Llm\LlmBinding;
use App\Models\AvatarConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Keeps an avatar config's LiveAvatar `llm_configuration` and the secret behind it in step with the
 * account's language-model binding.
 *
 * The secret holds the account's model key and is kept in the `provider_resources` ledger under
 * that key's fingerprint, so one account's configs share it, another account never reaches it, and
 * a new model key (LiveAvatar secrets are immutable) makes a new secret. The configuration id is
 * the adapter's own per-config state, `provider_state.llm_configuration_id`. The session token
 * request sends it as the top-level `llm_configuration_id`; nested under `avatar_persona`
 * LiveAvatar silently ignores it (see LiveAvatarPayload).
 *
 * Failures raise ProviderException so the sync job can retry or give up; forget() is the one
 * method that never throws, because an unreachable provider must not block unbinding a config.
 */
final class LiveAvatarLlmRegistrar
{
    private const STATE_KEY = 'llm_configuration_id';

    public function __construct(private readonly LiveAvatarApi $api, private readonly LiveAvatarLedger $ledger) {}

    /**
     * Creates the configuration, or updates the one the config already has, so that it points at
     * the binding's model and the secret holding the binding's key. Returns its id.
     *
     * @throws ProviderException
     */
    public function ensureConfiguration(AvatarConfig $config, LlmBinding $binding, ProviderAuth $auth): string
    {
        $fingerprint = substr(hash('sha256', $binding->apiKey()), 0, 16);
        [$response, $existing] = $this->write($config, $binding, $auth, $fingerprint);

        if ($this->api->isUnknownSecret($response)) {
            // The secret was deleted in LiveAvatar's dashboard: drop the ledger entry, create it again and write once more, never loop.
            $this->ledger->forget($config->account_id, $auth, ResourceKind::LlmSecret, $fingerprint);
            [$response, $existing] = $this->write($config, $binding, $auth, $fingerprint);
        }

        $this->api->orFail($response);

        if ($existing !== null) {
            return $existing;
        }

        $created = $this->api->stringAt($response, 'data.id') ?? throw ProviderException::noResponse();
        $this->store($config, $created);

        return $created;
    }

    /**
     * Writes the configuration against the secret holding the binding's key: an update of the one the config
     * already has, else a create. Returns the answer, and the stored id when the answer is an update's.
     *
     * @return array{0: Response, 1: string|null}
     *
     * @throws ProviderException
     */
    private function write(AvatarConfig $config, LlmBinding $binding, ProviderAuth $auth, string $fingerprint): array
    {
        $secretId = $this->ledger->secret(
            $config->account_id, $auth, ResourceKind::LlmSecret, $fingerprint, 'OPENAI_API_KEY', "hra-llm-{$fingerprint}", $binding->apiKey(),
        );

        $body = [
            'display_name' => "hra-{$config->id}",
            'model_name' => $binding->modelKey,
            // LiveAvatar joins this with its own leading slash; a trailing one gives `//chat/completions`, which 404s.
            'base_url' => rtrim($binding->baseUrl, '/'),
            'secret_id' => $secretId,
        ];

        $existing = $this->storedId($config);

        if ($existing !== null) {
            $response = $this->api->call($auth, 'PATCH', '/llm-configurations/'.rawurlencode($existing), $body);

            if ($response->status() !== 404) {
                return [$response, $existing];
            }

            // Deleted in LiveAvatar's dashboard, or it belongs to another key's space: forget it and create once, never loop.
            $this->store($config, null);
        }

        return [$this->api->call($auth, 'POST', '/llm-configurations', $body), null];
    }

    /**
     * Deletes the config's configuration and forgets its id, whatever the provider answers: once
     * this service stops referencing it, keeping the id serves nothing. Never throws.
     */
    public function forget(AvatarConfig $config, ProviderAuth $auth): void
    {
        $id = $this->storedId($config);

        if ($id === null) {
            return;
        }

        try {
            $response = $this->api->call($auth, 'DELETE', '/llm-configurations/'.rawurlencode($id));

            if (! $response->successful() && $response->status() !== 404) {
                Log::warning('LiveAvatar LLM configuration delete failed', ['status_class' => intdiv($response->status(), 100).'xx']);
            }
        } catch (ProviderException $error) {
            Log::warning('LiveAvatar LLM configuration delete failed', $error->context());
        }

        $this->store($config, null);
    }

    private function storedId(AvatarConfig $config): ?string
    {
        $id = $config->provider_state[self::STATE_KEY] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function store(AvatarConfig $config, ?string $id): void
    {
        $state = $config->provider_state;
        unset($state[self::STATE_KEY]);

        $config->forceFill(['provider_state' => $id === null ? $state : [...$state, self::STATE_KEY => $id]])->saveQuietly();
    }
}
