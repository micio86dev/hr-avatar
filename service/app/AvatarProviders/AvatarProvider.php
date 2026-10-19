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
 * that have an implementation.
 */
interface AvatarProvider
{
    /** How this provider's events reach the service. */
    public function ingestion(): Ingestion;

    /** What a client can configure and look up for this provider. Makes no provider call. */
    public function describe(): ProviderDescription;

    /**
     * The problems of an avatar config's `config`, `persona` and voice for this provider, empty when
     * there are none. Pure: nothing is sent to the provider.
     *
     * @param  array<array-key, mixed>  $config
     * @param  array<array-key, mixed>  $persona
     * @return list<FieldError>
     */
    public function validateConfig(array $config, ?VoiceBinding $voice, array $persona = []): array;

    /**
     * Whether the provider needs objects created for this config before a conversation can start,
     * which decides its initial sync status.
     */
    public function syncRequired(AvatarConfig $config): bool;

    /**
     * Creates the provider-side objects the config needs, or updates the ones it already has, and
     * never duplicates. Runs in a queued job, outside any database transaction.
     *
     * @throws ProviderException the provider refused the request or could not be reached
     * @throws SyncBlocked something the sync needs is missing; nothing was sent
     */
    public function sync(AvatarConfig $config, ProviderAuth $auth): SyncResult;

    /**
     * What the account can pick from at the provider for one of the resources `describe()` lists
     * (an avatar, a replica, a voice...), in the shared shape. Only called for a listed resource.
     *
     * @return list<CatalogueEntry>
     *
     * @throws ProviderException the provider refused the request or could not be reached
     */
    public function catalogue(string $resource, ProviderAuth $auth): array;

    /**
     * Starts a provider session for a conversation. Runs outside any database transaction.
     *
     * The conversation id is not part of the contract's ConversationSpec (which holds exactly the
     * host-neutral settings), so it is its own argument: adapters that name provider objects after
     * the conversation, or derive their references from it, need it.
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
