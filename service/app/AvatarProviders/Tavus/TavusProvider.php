<?php

namespace App\AvatarProviders\Tavus;

use App\AvatarProviders\AvatarProvider;
use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\FieldSpecValidator;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\JoinMaterial;
use App\AvatarProviders\ProviderDescription;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ProviderSession;
use App\AvatarProviders\SessionStatus;
use App\AvatarProviders\SyncResult;
use App\AvatarProviders\TranscriptLine;
use App\AvatarProviders\TranscriptNotReady;
use App\AvatarProviders\TranscriptShapeError;
use App\AvatarProviders\VoiceBinding;
use App\Credentials\ProviderAuth;
use App\Models\AvatarConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The Tavus adapter. Tavus reports what happens in a conversation by calling back, so events
 * arrive as callbacks (`Ingestion::Callback`) and nothing polls it.
 *
 * Every failure becomes a ProviderException that carries the status and nothing of what Tavus
 * wrote or what was sent: the response body and the exception a failed call raised both hold
 * things (the key among them) that must not reach a message or a log.
 *
 * Calls address the account's conversations one at a time: the one being created, or the one the
 * caller names. Nothing lists the account's conversations (see TavusConcurrencyGuard).
 */
final class TavusProvider implements AvatarProvider
{
    private const BASE_URL = 'https://tavusapi.com/v2';

    public function __construct(
        private readonly TavusConcurrencyGuard $guard = new TavusConcurrencyGuard,
        private readonly TavusPersonaSync $personas = new TavusPersonaSync,
        private readonly TavusCatalogue $catalogue = new TavusCatalogue,
    ) {}

    public function ingestion(): Ingestion
    {
        return Ingestion::Callback;
    }

    public function describe(): ProviderDescription
    {
        return new ProviderDescription(
            fields: TavusFieldSpecs::config(),
            personaFields: TavusFieldSpecs::persona(),
            resources: ['replica', 'pal'],
            voiceEngines: VoiceBinding::ENGINES,
        );
    }

    public function validateConfig(array $config, ?VoiceBinding $voice, array $persona = []): array
    {
        return FieldSpecValidator::validateConfig($this->describe(), $config, $voice, $persona);
    }

    /**
     * Always: the Tavus persona is where the persona knobs, the voice and the language-model binding live.
     */
    public function syncRequired(AvatarConfig $config): bool
    {
        return true;
    }

    public function sync(AvatarConfig $config, ProviderAuth $auth): SyncResult
    {
        return $this->personas->sync($config, $auth);
    }

    public function catalogue(string $resource, ProviderAuth $auth): array
    {
        return $this->catalogue->list($resource, $auth);
    }

    /**
     * The conversation id is not sent: Tavus names its conversations itself, and answers with the id.
     */
    public function start(string $conversationId, ConversationSpec $spec, AvatarConfig $config, ProviderAuth $auth, ?string $callbackUrl): ProviderSession
    {
        $body = TavusPayload::forConversation($spec, $config, $callbackUrl);

        try {
            $response = $this->guard->attempt(fn () => $this->http($auth)->post(self::BASE_URL.'/conversations', $body));
        } catch (ConnectionException) {
            throw ProviderException::noResponse();
        }

        if (! $response->successful()) {
            throw ProviderException::fromStatus($response->status());
        }

        $ref = $response->json('conversation_id');
        $url = $response->json('conversation_url');

        if (! is_string($ref) || $ref === '' || ! is_string($url) || $url === '') {
            throw ProviderException::noResponse();
        }

        // Tavus states no expiry for the room; it cannot outlive the call's own maximum.
        return new ProviderSession($ref, null, new JoinMaterial(
            provider: 'tavus',
            transport: 'daily',
            conversationUrl: $url,
            sessionToken: null,
            expiresAt: now()->addSeconds($spec->maxDurationSeconds)->toDateTimeImmutable(),
        ));
    }

    /**
     * Ends the conversation. Tavus answers 404 for one it no longer has, which is the goal reached.
     * Never throws: a failure is logged by its status class and reported as not released.
     */
    public function teardown(string $sessionRef, ?string $contextRef, ProviderAuth $auth): bool
    {
        try {
            $response = $this->http($auth)->post(self::BASE_URL.'/conversations/'.rawurlencode($sessionRef).'/end');
        } catch (ConnectionException) {
            Log::warning('Tavus teardown failed', ProviderException::noResponse()->context());

            return false;
        }

        if ($response->successful() || $response->status() === 404) {
            return true;
        }

        Log::warning('Tavus teardown failed', ProviderException::fromStatus($response->status())->context());

        return false;
    }

    /**
     * The transcript is part of the conversation Tavus reports once the call is over: it is not
     * there while the call is active or before Tavus has prepared it, and an empty one counts as
     * not there yet.
     */
    public function fetchTranscript(string $sessionRef, ProviderAuth $auth): array
    {
        $rows = null;

        foreach ($this->events($this->conversation($sessionRef, $auth)) as $event) {
            if (($event['event_type'] ?? null) === 'application.transcription_ready') {
                $rows = data_get($event, 'properties.transcript');

                break;
            }
        }

        if (! is_array($rows) || $rows === []) {
            throw new TranscriptNotReady('Tavus has not delivered the transcript yet.');
        }

        return $this->lines($rows);
    }

    /**
     * A `transcription_ready` callback carries the same rows a fetched conversation lists.
     */
    public function callbackTranscript(array $payload): array
    {
        $rows = data_get($payload, 'properties.transcript');

        return is_array($rows) ? $this->lines($rows) : [];
    }

    /**
     * An error is never an end: a failed call, or an answer with no usable status, raises. Only the
     * terminal status Tavus documents (`ended`) is an end; any other word, `active` included, is not,
     * and an unrecognised one is logged by value (cut short) so a new status is noticed. The max-duration
     * sweeper still bounds a conversation that is read as running for ever.
     */
    public function fetchSession(string $sessionRef, ProviderAuth $auth): SessionStatus
    {
        $conversation = $this->conversation($sessionRef, $auth);
        $status = $conversation['status'] ?? null;

        if (! is_string($status) || $status === '') {
            throw ProviderException::noResponse();
        }

        if ($status !== 'ended') {
            if ($status !== 'active') {
                Log::warning('Tavus returned an unrecognised conversation status', ['status' => Str::limit($status, 32, '')]);
            }

            return new SessionStatus(false, null);
        }

        // Only `shutdown_reason` is read at the top level: Tavus documents `reason` only inside an event's properties.
        $reason = $conversation['shutdown_reason'] ?? null;

        foreach ($this->events($conversation) as $event) {
            if (($event['event_type'] ?? null) === 'system.shutdown') {
                $reason ??= TavusEventMapper::endReason((array) ($event['properties'] ?? []));
            }
        }

        return new SessionStatus(true, is_string($reason) && $reason !== '' ? $reason : null);
    }

    public function parseCallback(array $payload): array
    {
        return TavusEventMapper::map($payload);
    }

    /**
     * The one conversation, with its events.
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    private function conversation(string $sessionRef, ProviderAuth $auth): array
    {
        try {
            $response = $this->http($auth)->get(self::BASE_URL.'/conversations/'.rawurlencode($sessionRef).'?verbose=true');
        } catch (ConnectionException) {
            throw ProviderException::noResponse();
        }

        if (! $response->successful()) {
            throw ProviderException::fromStatus($response->status());
        }

        $conversation = $response->json();

        if (! is_array($conversation)) {
            throw ProviderException::noResponse();
        }

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $conversation
     * @return list<array<string, mixed>>
     */
    private function events(array $conversation): array
    {
        $events = $conversation['events'] ?? null;

        return is_array($events) ? array_values(array_filter($events, 'is_array')) : [];
    }

    /**
     * The rows of a transcript as lines, in the provider's order.
     *
     * @param  array<array-key, mixed>  $rows
     * @return list<TranscriptLine>
     *
     * @throws TranscriptShapeError
     */
    private function lines(array $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $line = $this->line($row);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * One transcript row as a line, null for the system prompt Tavus lists first.
     *
     * @throws TranscriptShapeError a row that is not an object, or has an unknown role or no text
     */
    private function line(mixed $row): ?TranscriptLine
    {
        if (! is_array($row)) {
            throw new TranscriptShapeError('A Tavus transcript row is not an object.');
        }

        $role = match ($row['role'] ?? null) {
            'system' => null,
            'assistant', 'replica' => TranscriptLine::AVATAR,
            'user' => TranscriptLine::USER,
            default => throw new TranscriptShapeError('A Tavus transcript row has a missing or unknown role.'),
        };

        if ($role === null) {
            return null;
        }

        $text = $row['content'] ?? null;

        if (! is_string($text)) {
            throw new TranscriptShapeError('A Tavus transcript row has no text.');
        }

        $offset = $row['seconds_from_start'] ?? null;

        return new TranscriptLine($role, $text, TavusEventMapper::time($row['timestamp'] ?? null), is_int($offset) || is_float($offset) ? (float) $offset : null);
    }

    private function http(ProviderAuth $auth): PendingRequest
    {
        return Http::withHeaders(['x-api-key' => $auth->apiKey()]);
    }
}
