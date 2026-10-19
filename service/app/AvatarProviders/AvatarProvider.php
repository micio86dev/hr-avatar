<?php

namespace App\AvatarProviders;

use App\Credentials\ProviderAuth;
use App\Models\AvatarConfig;

/**
 * The provider seam: everything a provider does for a conversation, in host-neutral terms.
 * Provider specifics live only in the adapters behind it, and no caller branches on a provider's
 * name; an operation that does not apply to a provider returns a neutral value.
 *
 * Every network operation receives the resolved ProviderAuth (last, except that `start` is
 * followed by its optional callback URL). This interface grows slice by slice with the operations
 * that have an implementation; the field specs, sync and catalogue operations arrive with theirs.
 */
interface AvatarProvider
{
    /** How this provider's events reach the service. */
    public function ingestion(): Ingestion;

    /**
     * Starts a provider session for a conversation. Runs outside any database transaction.
     *
     * The conversation id is not part of the contract's ConversationSpec (which holds exactly the
     * host-neutral settings), so it is its own argument: adapters that name provider objects after
     * the conversation, and the Mock, need it.
     *
     * @throws ProviderException
     */
    public function start(string $conversationId, ConversationSpec $spec, AvatarConfig $config, ProviderAuth $auth, ?string $callbackUrl): ProviderSession;

    /**
     * Ends a provider session and releases what was created for it. Best effort and idempotent:
     * true once the session is confirmed ended (an already ended or unknown session counts), false
     * otherwise. Never throws.
     */
    public function teardown(string $sessionRef, ?string $contextRef, ProviderAuth $auth): bool;

    /**
     * @return list<TranscriptLine>
     *
     * @throws TranscriptNotReady
     * @throws TranscriptShapeError
     * @throws ProviderException
     */
    public function fetchTranscript(string $sessionRef, ProviderAuth $auth): array;

    /**
     * @throws ProviderException an error is never reported as "ended"
     */
    public function fetchSession(string $sessionRef, ProviderAuth $auth): SessionStatus;

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ProviderEvent> empty for an adapter whose ingestion is not Callback
     */
    public function parseCallback(array $payload): array;
}
