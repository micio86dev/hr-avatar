<?php

namespace App\Llm;

use LogicException;

/**
 * A resolved language-model binding, ready for an adapter to send: the exact vendor model
 * string, the endpoint, and the account's key. The key is private: JSON and property listings
 * leave it out, dumps redact it and serialization is refused, which an Eloquent model could not
 * promise. A cast to array still exposes private properties, so the key is read only through
 * apiKey(), by the adapter that sends it.
 */
final readonly class LlmBinding
{
    public function __construct(
        public string $modelKey,
        public string $baseUrl,
        #[\SensitiveParameter] private string $apiKey,
    ) {}

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Honoured by var_dump, print_r and Symfony's dumper. var_export ignores it and dumps every
     * property, so the real boundary for that is the architecture test banning the function.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['modelKey' => $this->modelKey, 'baseUrl' => $this->baseUrl, 'apiKey' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A language-model binding cannot be serialized; carry the config id and resolve it where it is used.');
    }
}
