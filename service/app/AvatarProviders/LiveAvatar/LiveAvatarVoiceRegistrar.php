<?php

namespace App\AvatarProviders\LiveAvatar;

use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ResourceKind;
use App\Credentials\CredentialResolver;
use App\Credentials\ProviderAuth;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Binds a Cartesia or ElevenLabs voice into LiveAvatar so a session can speak with it, and
 * remembers the answer in the `provider_resources` ledger.
 *
 * What LiveAvatar does, seen live (2026-10-03):
 *  1. A bind is not idempotent: three identical binds give three voices. The ledger is the only
 *     idempotency, and a lock around "read ledger, bind, record" keeps two starts from both binding.
 *  2. It accepts any vendor voice id with a 200, so a successful bind proves nothing about the
 *     voice; refusing unknown ids is the catalogue's job, before a config is saved.
 *  3. The bound voice's `language` is always `en`, whatever the voice speaks. It is never read or
 *     returned: the spoken language comes from `avatar_persona.language`, which is the conversation's.
 *  4. An unknown `secret_id` answers 400 with code 4000. That is a secret deleted in LiveAvatar's
 *     dashboard: the entry is dropped, the secret recreated once and the bind retried once.
 *
 * The vendor key is the account's own (else the platform's), and every ledger row is keyed by its
 * fingerprint: a voice bound through a secret that holds a key the account has since replaced is
 * not reused. A voice that cannot be bound raises ProviderException, before any billable session.
 * An unsupported engine and a missing vendor key are such failures; they are raised as "no
 * response" because nothing was sent, and callers that need to tell them apart check first.
 */
final class LiveAvatarVoiceRegistrar
{
    /** LiveAvatar `SecretTypeEnum` per voice vendor. */
    private const SECRET_TYPES = ['cartesia' => 'CARTESIA_API_KEY', 'elevenlabs' => 'ELEVENLABS_API_KEY'];

    /** Longest voice name LiveAvatar documents. */
    private const NAME_MAX = 64;

    /** The lock outlives any single bind, then expires on its own. */
    private const LOCK_SECONDS = 60;

    /**
     * @param  int  $lockWaitSeconds  how long a start waits for a bind already in flight before giving up
     */
    public function __construct(
        private readonly LiveAvatarApi $api,
        private readonly LiveAvatarLedger $ledger,
        private readonly CredentialResolver $credentials,
        private readonly int $lockWaitSeconds = 10,
    ) {}

    /**
     * The LiveAvatar voice id for a vendor voice, bound on first use. Answered from the ledger
     * without a call on every later start.
     *
     * @throws ProviderException
     */
    public function ensureVoice(string $accountId, ProviderAuth $auth, string $engine, string $vendorVoiceId): string
    {
        $type = self::SECRET_TYPES[$engine] ?? throw ProviderException::noResponse();
        $vendor = $this->credentials->for($accountId, $engine) ?? throw ProviderException::noResponse();
        $secretKey = "{$engine}:{$vendor->fingerprint}";
        $voiceKey = "{$secretKey}:{$vendorVoiceId}";

        $known = $this->ledger->find($accountId, $auth, ResourceKind::Voice, $voiceKey);

        if ($known !== null) {
            return $known;
        }

        $lock = Cache::lock("hra:liveavatar:bind:{$accountId}:{$auth->fingerprint}:".sha1($voiceKey), self::LOCK_SECONDS);

        try {
            $lock->block($this->lockWaitSeconds);
        } catch (LockTimeoutException) {
            // The start that holds the lock may have finished while this one waited.
            return $this->ledger->find($accountId, $auth, ResourceKind::Voice, $voiceKey) ?? throw ProviderException::noResponse();
        }

        try {
            // Read again under the lock: the winner of an earlier race has recorded by now.
            return $this->ledger->find($accountId, $auth, ResourceKind::Voice, $voiceKey)
                ?? $this->bind($accountId, $auth, $engine, $type, $vendor, $secretKey, $voiceKey, $vendorVoiceId);
        } finally {
            $lock->release();
        }
    }

    private function bind(string $accountId, ProviderAuth $auth, string $engine, string $type, ProviderAuth $vendor, string $secretKey, string $voiceKey, string $vendorVoiceId): string
    {
        $secretId = $this->secret($accountId, $auth, $engine, $type, $vendor, $secretKey);
        $response = $this->bindRequest($auth, $engine, $vendorVoiceId, $secretId);

        if ($this->isUnknownSecret($response)) {
            $this->ledger->forget($accountId, $auth, ResourceKind::VendorSecret, $secretKey);
            $response = $this->bindRequest($auth, $engine, $vendorVoiceId, $this->secret($accountId, $auth, $engine, $type, $vendor, $secretKey));
        }

        $created = $this->api->stringAt($this->api->orFail($response), 'data.voice_id') ?? throw ProviderException::noResponse();
        $recorded = $this->ledger->record($accountId, $auth, ResourceKind::Voice, $voiceKey, $created);

        if ($recorded !== $created) {
            $this->ledger->removeQuietly($auth, '/voices/'.rawurlencode($created));
        }

        return $recorded;
    }

    private function secret(string $accountId, ProviderAuth $auth, string $engine, string $type, ProviderAuth $vendor, string $secretKey): string
    {
        return $this->ledger->secret(
            $accountId, $auth, ResourceKind::VendorSecret, $secretKey, $type, "hra-{$engine}-{$vendor->fingerprint}", $vendor->apiKey(),
        );
    }

    private function bindRequest(ProviderAuth $auth, string $engine, string $vendorVoiceId, string $secretId): Response
    {
        return $this->api->call($auth, 'POST', '/voices/third_party', [
            'provider_voice_id' => $vendorVoiceId,
            'secret_id' => $secretId,
            'name' => mb_substr("hra-{$engine}-{$vendorVoiceId}", 0, self::NAME_MAX),
        ]);
    }

    private function isUnknownSecret(Response $response): bool
    {
        return $response->status() === 400
            && $response->json('code') === 4000
            && str_contains((string) $response->json('message'), 'Secret');
    }
}
