<?php

namespace App\AvatarProviders\LiveAvatar;

use App\AvatarProviders\AvatarProvider;
use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\JoinMaterial;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ProviderSession;
use App\AvatarProviders\SessionStatus;
use App\Credentials\ProviderAuth;
use App\Models\AvatarConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * The HeyGen LiveAvatar adapter (registry id `heygen`; `liveavatar` is only the browser transport
 * and this directory). LiveAvatar has no webhooks, so its events are polled.
 *
 * Endpoints (LiveAvatar v1, https://docs.liveavatar.com/openapi.json):
 *   POST /contexts        the avatar's prompt and opening text; the id is at `data.id`
 *   POST /sessions/token  the session token and session id, at `data.session_token` / `data.session_id`
 *   DELETE /contexts/{id} best-effort cleanup
 *
 * No provider key, request or response body reaches an exception message or a log (see LiveAvatarApi).
 */
final class LiveAvatarProvider implements AvatarProvider
{
    /** LiveAvatar documents no token lifetime; the browser joins right after connect, so one hour is a ceiling, not a promise. */
    private const JOIN_WINDOW_SECONDS = 3600;

    /** The context delete runs inside a request, so it is bounded well under the client's default. */
    private const CONTEXT_DELETE_TIMEOUT_SECONDS = 5;

    public function __construct(private readonly LiveAvatarApi $api, private readonly LiveAvatarVoiceRegistrar $voices) {}

    public function ingestion(): Ingestion
    {
        return Ingestion::Poll;
    }

    /**
     * Runs the voice and the language-model lookups first (an unbindable voice fails before anything
     * is created), then the context, then the token. A context that no session will ever use is
     * deleted, whatever stopped the token call. `$callbackUrl` is ignored: there is nothing to call back.
     */
    public function start(string $conversationId, ConversationSpec $spec, AvatarConfig $config, ProviderAuth $auth, ?string $callbackUrl): ProviderSession
    {
        [$engine, $voiceId] = $this->voice($config, $auth);

        $contextId = $this->api->stringAt(
            $this->api->orFail($this->api->call($auth, 'POST', '/contexts', LiveAvatarPayload::context("hra-{$conversationId}-".Str::ulid(), $spec))),
            'data.id',
        ) ?? throw ProviderException::noResponse();

        try {
            $token = $this->api->orFail($this->api->call($auth, 'POST', '/sessions/token', LiveAvatarPayload::sessionToken(
                $config->config, $spec->language, $contextId, $this->llmConfigurationId($config), $voiceId, $engine,
            )));

            // A session without an id could never be torn down, so it is as unusable as one without a token.
            $sessionToken = $this->api->stringAt($token, 'data.session_token') ?? throw ProviderException::noResponse();
            $sessionId = $this->api->stringAt($token, 'data.session_id') ?? throw ProviderException::noResponse();
        } catch (Throwable $failure) {
            $this->deleteContext($contextId, $auth);

            throw $failure;
        }

        return new ProviderSession($sessionId, $contextId, new JoinMaterial(
            provider: LiveAvatarApi::PROVIDER_ID,
            transport: 'liveavatar',
            conversationUrl: null,
            sessionToken: $sessionToken,
            expiresAt: now()->addSeconds(self::JOIN_WINDOW_SECONDS)->toDateTimeImmutable(),
        ));
    }

    public function teardown(string $sessionRef, ?string $contextRef, ProviderAuth $auth): bool
    {
        throw new LogicException('LiveAvatar teardown arrives with slice 14.');
    }

    public function fetchTranscript(string $sessionRef, ProviderAuth $auth): array
    {
        throw new LogicException('LiveAvatar transcript arrives with slice 14.');
    }

    public function fetchSession(string $sessionRef, ProviderAuth $auth): SessionStatus
    {
        throw new LogicException('LiveAvatar session status arrives with slice 14.');
    }

    public function parseCallback(array $payload): array
    {
        throw new LogicException('LiveAvatar callbacks arrive with slice 14.');
    }

    /**
     * The vendor the config's voice is bound from (null for the provider's own voice or none) and the
     * LiveAvatar voice id to send. An external voice is looked up in the ledger and bound once if the
     * ledger lost it; when it cannot be bound the start fails, because falling back to the avatar's
     * default voice would put the wrong voice in front of the user and report nothing.
     *
     * @return array{0: string|null, 1: string|null}
     *
     * @throws ProviderException
     */
    private function voice(AvatarConfig $config, ProviderAuth $auth): array
    {
        $engine = $config->voice['engine'] ?? null;
        $voiceId = $config->voice['voice_id'] ?? null;

        if (! is_string($engine) || ! is_string($voiceId)) {
            return [null, null];
        }

        if ($engine === 'provider') {
            return [null, $voiceId];
        }

        return [$engine, $this->voices->ensureVoice($config->account_id, $auth, $engine, $voiceId)];
    }

    private function llmConfigurationId(AvatarConfig $config): ?string
    {
        $id = $config->provider_state['llm_configuration_id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * Best effort and silent on failure: the session it served is gone or was never created, so a
     * context that survives costs a stale entry, not a conversation. An already deleted one is success.
     */
    private function deleteContext(string $contextId, ProviderAuth $auth): void
    {
        try {
            $response = $this->api->call($auth, 'DELETE', '/contexts/'.rawurlencode($contextId), timeout: self::CONTEXT_DELETE_TIMEOUT_SECONDS);

            if (! $response->successful() && $response->status() !== 404) {
                Log::warning('LiveAvatar context delete failed', ['status_class' => intdiv($response->status(), 100).'xx']);
            }
        } catch (ProviderException $error) {
            Log::warning('LiveAvatar context delete failed', $error->context());
        }
    }
}
