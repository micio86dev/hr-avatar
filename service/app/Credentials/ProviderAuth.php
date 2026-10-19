<?php

namespace App\Credentials;

use InvalidArgumentException;
use LogicException;

/**
 * A resolved provider key on its way to the one place that sends it. It cannot be logged by
 * accident (dumps redact the key, JSON omits it) and cannot travel through a queue (it refuses
 * to be serialized): a job carries ids and resolves the credential where it runs.
 */
final class ProviderAuth
{
    /** The owner of a key that belongs to the platform rather than to an account. */
    public const PLATFORM = 'platform';

    /** First 16 hex characters of the key's sha256: tells two keys apart without revealing either. */
    public readonly string $fingerprint;

    /**
     * @param  string  $owner  an account id, or PLATFORM
     */
    public function __construct(#[\SensitiveParameter] private readonly string $apiKey, public readonly string $owner)
    {
        if ($apiKey === '') {
            throw new InvalidArgumentException('A provider key must not be empty.');
        }

        $this->fingerprint = substr(hash('sha256', $apiKey), 0, 16);
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['apiKey' => '[redacted]', 'fingerprint' => $this->fingerprint, 'owner' => $this->owner];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A provider credential cannot be serialized; carry its owner and resolve it where it is used.');
    }
}
