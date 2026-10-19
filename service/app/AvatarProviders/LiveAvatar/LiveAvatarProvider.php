<?php

namespace App\AvatarProviders\LiveAvatar;

use App\AvatarProviders\AvatarProvider;
use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\FieldSpecValidator;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\JoinMaterial;
use App\AvatarProviders\ProviderDescription;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ProviderSession;
use App\AvatarProviders\SessionStatus;
use App\AvatarProviders\SyncBlocked;
use App\AvatarProviders\SyncResult;
use App\AvatarProviders\TranscriptLine;
use App\AvatarProviders\TranscriptNotReady;
use App\AvatarProviders\TranscriptShapeError;
use App\AvatarProviders\VoiceBinding;
use App\Credentials\CredentialResolver;
use App\Credentials\ProviderAuth;
use App\Llm\LlmBindingResolver;
use App\Models\AvatarConfig;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
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

    /** The `SessionEndReasonEnum` value recorded when this service ends a session itself. */
    private const STOP_REASON = 'USER_CLOSED';

    /** The context delete runs inside a request, so it is bounded well under the client's default. */
    private const CONTEXT_DELETE_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly LiveAvatarApi $api,
        private readonly LiveAvatarVoiceRegistrar $voices,
        private readonly LiveAvatarLlmRegistrar $llm,
        private readonly LlmBindingResolver $bindings,
        private readonly CredentialResolver $credentials,
    ) {}

    public function ingestion(): Ingestion
    {
        return Ingestion::Poll;
    }

    public function describe(): ProviderDescription
    {
        return new ProviderDescription(
            fields: LiveAvatarFieldSpecs::config(),
            personaFields: LiveAvatarFieldSpecs::persona(),
            resources: ['avatar', 'voice'],
            voiceEngines: VoiceBinding::ENGINES,
        );
    }

    public function validateConfig(array $config, ?VoiceBinding $voice, array $persona = []): array
    {
        return FieldSpecValidator::validateConfig($this->describe(), $config, $voice, $persona);
    }

    /**
     * Only a config that needs a LiveAvatar object: a language-model configuration (bound, or left
     * over from a binding that was removed, so the sync can delete it) or an external voice to bind.
     * A plain config, or one with the stock voice, starts a session with nothing prepared.
     */
    public function syncRequired(AvatarConfig $config): bool
    {
        return $config->llm_model_id !== null
            || VoiceBinding::fromArray($config->voice)?->isExternal() === true
            || is_string($config->provider_state['llm_configuration_id'] ?? null);
    }

    /**
     * Registers what a session of this config uses and LiveAvatar has to hold: the language-model
     * configuration (created or updated, or deleted when the model is no longer bound) and the
     * external voice (bound once, found again through the ledger). The references are kept by the
     * registrars: the configuration id in `provider_state`, the voice in the ledger.
     *
     * Everything that can be missing is checked before the first request, so a sync that is
     * blocked has sent nothing.
     */
    public function sync(AvatarConfig $config, ProviderAuth $auth): SyncResult
    {
        $binding = $config->llm_model_id === null ? null : ($this->bindings->resolve($config) ?? throw SyncBlocked::llmCredentialMissing());
        $voice = VoiceBinding::fromArray($config->voice);
        $external = $voice?->isExternal() === true ? $voice : null;

        if ($external !== null && $this->credentials->for($config->account_id, $external->engine) === null) {
            throw SyncBlocked::voiceCredentialMissing();
        }

        if ($binding === null) {
            $this->llm->forget($config, $auth);
        } else {
            $this->llm->ensureConfiguration($config, $binding, $auth);
        }

        if ($external !== null) {
            $this->voices->ensureVoice($config->account_id, $auth, $external->engine, $external->voiceId);
        }

        return SyncResult::synced();
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

    /**
     * `POST /sessions/stop` is the only way LiveAvatar offers to end a session (`DELETE /sessions/{id}`
     * answers 405 and leaves it running). A session that is already gone (404) counts as released.
     * The context is deleted only once the session is released: one still attached to a live session
     * must not be deleted from under it. A failed context delete never changes the result.
     */
    public function teardown(string $sessionRef, ?string $contextRef, ProviderAuth $auth): bool
    {
        try {
            $response = $this->api->call($auth, 'POST', '/sessions/stop', ['session_id' => $sessionRef, 'reason' => self::STOP_REASON]);
        } catch (ProviderException $error) {
            Log::warning('LiveAvatar teardown failed', $error->context());

            return false;
        }

        if (! $response->successful() && $response->status() !== 404) {
            Log::warning('LiveAvatar teardown failed', ['status_class' => intdiv($response->status(), 100).'xx']);

            return false;
        }

        $this->deleteContext($contextRef, $auth);

        return true;
    }

    /**
     * `GET /sessions/{id}/transcript` answers `data.transcript_data[]` rows of `{role, transcript,
     * absolute_timestamp, relative_timestamp}` and `data.session_active`. Only the first page is
     * read: how `next_timestamp` paginates is undocumented (open item in the research notes).
     */
    public function fetchTranscript(string $sessionRef, ProviderAuth $auth): array
    {
        $response = $this->api->orFail($this->api->call($auth, 'GET', '/sessions/'.rawurlencode($sessionRef).'/transcript'));

        if ($response->json('data.session_active') === true) {
            throw new TranscriptNotReady('The LiveAvatar session is still active.');
        }

        $rows = $response->json('data.transcript_data');

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new TranscriptShapeError('The LiveAvatar transcript has no list of rows.');
        }

        return array_map($this->line(...), $rows);
    }

    /**
     * `GET /sessions/{id}`: a session is over when `end_at` or `end_reason` is set. A reply that is
     * not a session is a failure, never an end: a wrong "ended" would cut a live conversation.
     */
    public function fetchSession(string $sessionRef, ProviderAuth $auth): SessionStatus
    {
        $session = $this->api->orFail($this->api->call($auth, 'GET', '/sessions/'.rawurlencode($sessionRef)))->json('data');

        if (! is_array($session)) {
            throw ProviderException::noResponse();
        }

        $reason = $session['end_reason'] ?? null;
        $ended = ($session['end_at'] ?? null) !== null || $reason !== null;

        return new SessionStatus($ended, $ended ? (is_string($reason) && $reason !== '' ? $reason : 'unknown') : null);
    }

    public function parseCallback(array $payload): array
    {
        return [];
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
    private function deleteContext(?string $contextRef, ProviderAuth $auth): void
    {
        if ($contextRef === null || $contextRef === '') {
            return;
        }

        try {
            $response = $this->api->call($auth, 'DELETE', '/contexts/'.rawurlencode($contextRef), timeout: self::CONTEXT_DELETE_TIMEOUT_SECONDS);

            if (! $response->successful() && $response->status() !== 404) {
                Log::warning('LiveAvatar context delete failed', ['status_class' => intdiv($response->status(), 100).'xx']);
            }
        } catch (ProviderException $error) {
            Log::warning('LiveAvatar context delete failed', $error->context());
        }
    }

    /**
     * `user` and `avatar` are the contract. `assistant` and `agent` are read as the avatar because the
     * vocabulary the transcript endpoint uses was never confirmed beyond them; any other role is drift
     * and raises, so a new one cannot be quietly attributed to the wrong side.
     */
    private function line(mixed $row): TranscriptLine
    {
        if (! is_array($row) || ! is_string($row['role'] ?? null) || ! is_string($row['transcript'] ?? null)) {
            throw new TranscriptShapeError('A LiveAvatar transcript row has no role or text.');
        }

        $role = match ($row['role']) {
            'user' => TranscriptLine::USER,
            'avatar', 'assistant', 'agent' => TranscriptLine::AVATAR,
            default => throw new TranscriptShapeError('A LiveAvatar transcript row has an unknown role.'),
        };

        return new TranscriptLine($role, $row['transcript'], self::instant($row['absolute_timestamp'] ?? null), self::offsetSeconds($row['relative_timestamp'] ?? null));
    }

    /**
     * Assumption: the unit of `absolute_timestamp` is undocumented, so a number below 1e11 is read as epoch
     * seconds and anything above as milliseconds (both are valid until the year 5138); replace with the
     * documented unit once the transcript contract is confirmed. A value that is no time is null.
     */
    private static function instant(mixed $value): ?DateTimeInterface
    {
        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestampMsUTC((int) ($value < 1e11 ? $value * 1000 : $value));
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** `relative_timestamp` is milliseconds from the start of the session (assumed, like its sibling). */
    private static function offsetSeconds(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value / 1000 : null;
    }
}
