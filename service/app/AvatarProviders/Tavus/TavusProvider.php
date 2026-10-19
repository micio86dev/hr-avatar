<?php

namespace App\AvatarProviders\Tavus;

use App\AvatarProviders\AvatarProvider;
use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\JoinMaterial;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ProviderSession;
use App\AvatarProviders\SessionStatus;
use App\Credentials\ProviderAuth;
use App\Models\AvatarConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use LogicException;

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

    public function __construct(private readonly TavusConcurrencyGuard $guard = new TavusConcurrencyGuard) {}

    public function ingestion(): Ingestion
    {
        return Ingestion::Callback;
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

    // ponytail: the operations below arrive in the next slice, which replaces these.
    public function teardown(string $sessionRef, ?string $contextRef, ProviderAuth $auth): bool
    {
        throw new LogicException('Not implemented yet.');
    }

    public function fetchTranscript(string $sessionRef, ProviderAuth $auth): array
    {
        throw new LogicException('Not implemented yet.');
    }

    public function fetchSession(string $sessionRef, ProviderAuth $auth): SessionStatus
    {
        throw new LogicException('Not implemented yet.');
    }

    public function parseCallback(array $payload): array
    {
        throw new LogicException('Not implemented yet.');
    }

    private function http(ProviderAuth $auth): PendingRequest
    {
        return Http::withHeaders(['x-api-key' => $auth->apiKey()]);
    }
}
