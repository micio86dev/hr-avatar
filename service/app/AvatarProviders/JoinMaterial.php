<?php

namespace App\AvatarProviders;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * What the browser needs to join a provider's session, and nothing server-side: no API key and no
 * provider ref. The session token is the provider's ephemeral token for this one session and is
 * the one member a dump redacts. `transport` is the name of the browser-side transport that can
 * join the session: opaque here, owned by the adapter that returns it, and loaded by that name in
 * the SDK. `toArray()` is the wire form the SDK reads.
 */
final readonly class JoinMaterial
{
    public function __construct(
        public string $provider,
        public string $transport,
        public ?string $conversationUrl,
        #[\SensitiveParameter] public ?string $sessionToken,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($provider === '' || $transport === '') {
            throw new InvalidArgumentException('Join material must name its provider and the transport that joins it.');
        }
    }

    /**
     * @return array{provider: string, transport: string, conversation_url: string|null, session_token: string|null, expires_at: string}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'transport' => $this->transport,
            'conversation_url' => $this->conversationUrl,
            'session_token' => $this->sessionToken,
            'expires_at' => Carbon::instance($this->expiresAt)->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'provider' => $this->provider,
            'transport' => $this->transport,
            'conversationUrl' => $this->conversationUrl,
            'sessionToken' => $this->sessionToken === null ? null : '[redacted]',
            'expiresAt' => $this->expiresAt,
        ];
    }
}
