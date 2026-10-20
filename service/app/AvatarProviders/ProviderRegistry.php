<?php

namespace App\AvatarProviders;

/**
 * Resolves a provider id to its adapter. Everything it knows lives in `config/hr-avatar.php`
 * (`providers`: id => adapter class, whether it needs a credential, and optionally the prefix of
 * its event keys), so adding a provider is one registry entry and one adapter (plus, when it takes
 * a credential, its entries in `credential_providers` and `platform_keys`).
 */
final class ProviderRegistry
{
    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->entries());
    }

    /**
     * @throws UnknownProvider
     */
    public function get(string $id): AvatarProvider
    {
        /** @var AvatarProvider */
        return app($this->entry($id)['adapter']);
    }

    /**
     * The ids whose adapters ingest events the given way.
     *
     * @return list<string>
     */
    public function withIngestion(Ingestion $mode): array
    {
        return array_values(array_filter($this->ids(), fn (string $id) => $this->get($id)->ingestion() === $mode));
    }

    /**
     * @throws UnknownProvider
     */
    public function requiresCredential(string $id): bool
    {
        return $this->entry($id)['credential'];
    }

    /**
     * The prefix of the idempotency keys of a provider's events: its own id unless the entry says otherwise.
     *
     * @throws UnknownProvider
     */
    public function eventKeyPrefix(string $id): string
    {
        return $this->entry($id)['event_key_prefix'] ?? $id;
    }

    /**
     * @return array<string, array{adapter: class-string<AvatarProvider>, credential: bool, event_key_prefix?: string}>
     */
    private function entries(): array
    {
        /** @var array<string, array{adapter: class-string<AvatarProvider>, credential: bool, event_key_prefix?: string}> */
        return config()->array('hr-avatar.providers');
    }

    /**
     * @return array{adapter: class-string<AvatarProvider>, credential: bool, event_key_prefix?: string}
     *
     * @throws UnknownProvider
     */
    private function entry(string $id): array
    {
        return $this->entries()[$id] ?? throw new UnknownProvider('No provider is registered under that id.');
    }
}
