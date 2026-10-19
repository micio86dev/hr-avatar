<?php

namespace Tests\Support\AvatarProviders;

use App\AvatarProviders\AvatarProvider;
use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\JoinMaterial;
use App\AvatarProviders\ProviderSession;
use App\AvatarProviders\SessionStatus;
use App\Credentials\ProviderAuth;
use App\Models\AvatarConfig;

/**
 * A do-nothing adapter for the registry's tests, which need adapters of every ingestion mode
 * without naming a real provider.
 */
abstract class StubProvider implements AvatarProvider
{
    abstract public function ingestion(): Ingestion;

    public function start(string $conversationId, ConversationSpec $spec, AvatarConfig $config, ProviderAuth $auth, ?string $callbackUrl): ProviderSession
    {
        return new ProviderSession('stub_'.$conversationId, null, new JoinMaterial('stub', 'mock', null, null, new \DateTimeImmutable));
    }

    public function teardown(string $sessionRef, ?string $contextRef, ProviderAuth $auth): bool
    {
        return true;
    }

    public function fetchTranscript(string $sessionRef, ProviderAuth $auth): array
    {
        return [];
    }

    public function fetchSession(string $sessionRef, ProviderAuth $auth): SessionStatus
    {
        return new SessionStatus(false, null);
    }

    public function parseCallback(array $payload): array
    {
        return [];
    }
}
