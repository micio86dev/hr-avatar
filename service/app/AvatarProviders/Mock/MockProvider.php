<?php

namespace App\AvatarProviders\Mock;

use App\AvatarProviders\AvatarProvider;
use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\JoinMaterial;
use App\AvatarProviders\ProviderSession;
use App\AvatarProviders\SessionStatus;
use App\AvatarProviders\TranscriptLine;
use App\Credentials\ProviderAuth;
use App\Models\AvatarConfig;
use Illuminate\Support\Facades\Cache;

/**
 * A deterministic adapter with no network I/O, selected only by an avatar config whose provider is
 * `mock`. It exists so the whole conversation flow can run without a provider account.
 *
 * What the api process starts the worker process must see, so the little state it has (that a
 * session was ended, the opening text for the transcript) lives in the shared cache.
 */
final class MockProvider implements AvatarProvider
{
    private const STATE_TTL_SECONDS = 86400;

    private const SESSION_TTL_SECONDS = 3600;

    public function ingestion(): Ingestion
    {
        return Ingestion::None;
    }

    public function start(string $conversationId, ConversationSpec $spec, AvatarConfig $config, ProviderAuth $auth, ?string $callbackUrl): ProviderSession
    {
        $ref = "mock_{$conversationId}";

        Cache::put("hra:mock:opening:{$ref}", $spec->openingText ?? '', self::STATE_TTL_SECONDS);

        return new ProviderSession($ref, null, new JoinMaterial(
            provider: 'mock',
            transport: 'mock',
            conversationUrl: "mock://conversation/{$conversationId}",
            sessionToken: "mock_token_{$conversationId}",
            expiresAt: now()->addSeconds(self::SESSION_TTL_SECONDS)->toDateTimeImmutable(),
        ));
    }

    public function teardown(string $sessionRef, ?string $contextRef, ProviderAuth $auth): bool
    {
        Cache::put("hra:mock:ended:{$sessionRef}", true, self::STATE_TTL_SECONDS);

        return true;
    }

    public function fetchTranscript(string $sessionRef, ProviderAuth $auth): array
    {
        $opening = Cache::get("hra:mock:opening:{$sessionRef}");

        return is_string($opening) && $opening !== '' ? [new TranscriptLine(TranscriptLine::AVATAR, $opening, null, null)] : [];
    }

    public function fetchSession(string $sessionRef, ProviderAuth $auth): SessionStatus
    {
        $ended = Cache::has("hra:mock:ended:{$sessionRef}");

        return new SessionStatus($ended, $ended ? 'teardown' : null);
    }

    public function parseCallback(array $payload): array
    {
        return [];
    }
}
