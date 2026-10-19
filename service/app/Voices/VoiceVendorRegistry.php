<?php

namespace App\Voices;

/**
 * Resolves a provider id to its voice vendor, from `config('hr-avatar.voice_vendors')` alone. An id
 * that is not a vendor (an avatar provider with no voices of its own, such as the one that has to
 * route through an external engine) resolves to nothing.
 */
final class VoiceVendorRegistry
{
    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->entries());
    }

    public function get(?string $id): ?VoiceVendor
    {
        $class = $id === null ? null : ($this->entries()[$id] ?? null);

        return $class === null ? null : app($class);
    }

    /**
     * @return array<string, class-string<VoiceVendor>>
     */
    private function entries(): array
    {
        /** @var array<string, class-string<VoiceVendor>> */
        return config()->array('hr-avatar.voice_vendors');
    }
}
