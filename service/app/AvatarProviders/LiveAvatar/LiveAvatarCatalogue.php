<?php

namespace App\AvatarProviders\LiveAvatar;

use App\AvatarProviders\CatalogueEntry;
use App\AvatarProviders\ProviderException;
use App\Credentials\ProviderAuth;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Lists what a LiveAvatar account can pick from: avatars (the stock ones and the account's own; never
 * an expired one, nor one whose stated status is not ACTIVE) and voices (the public ones and, for an
 * account's own key, its own bound ones), as the shared CatalogueEntry. A voice's language is the one
 * LiveAvatar states, never inferred from its name.
 *
 * The public list is the catalogue; the account's own list is a bonus, so a failure there is logged
 * (status class only) and does not hide the public items. A voice has no preview url: its sample is
 * fetched through `POST /v1/voice-preview`, which the entry says with `preview_audio_via_api`.
 */
final class LiveAvatarCatalogue
{
    /** LiveAvatar's maximum; its default of twenty would cut the list. */
    private const PAGE_SIZE = 100;

    /** Bounds the loop so a provider that never ends the list cannot hold a worker. */
    private const MAX_PAGES = 20;

    public function __construct(private readonly LiveAvatarApi $api) {}

    /**
     * @return list<CatalogueEntry>
     *
     * @throws ProviderException when the public list fails
     * @throws InvalidArgumentException for a resource LiveAvatar does not list
     */
    public function list(string $resource, ProviderAuth $auth): array
    {
        return match ($resource) {
            'avatar' => $this->avatars($auth),
            'voice' => $this->voices($auth),
            default => throw new InvalidArgumentException('LiveAvatar has no such catalogue resource.'),
        };
    }

    /**
     * @return list<CatalogueEntry>
     */
    private function avatars(ProviderAuth $auth): array
    {
        $rows = [...$this->paged($auth, '/avatars/public'), ...$this->paged($auth, '/avatars', optional: true)];

        $entries = [];

        foreach ($rows as $row) {
            if (($row['is_expired'] ?? false) === true || ($row['status'] ?? 'ACTIVE') !== 'ACTIVE') {
                continue;
            }

            $entries[] = CatalogueEntry::fromRow($row, 'id', 'name', previewImageUrl: CatalogueEntry::text($row, 'preview_url'));
        }

        return $this->unique($entries);
    }

    /**
     * @return list<CatalogueEntry>
     */
    private function voices(ProviderAuth $auth): array
    {
        // The private voices of the platform key are the third-party voices every account on it has bound
        // (LiveAvatar holds them in one space), so listing them would show one account's voices to another.
        $public = $this->paged($auth, '/voices', ['voice_type' => 'public']);
        $private = $auth->owner === ProviderAuth::PLATFORM ? [] : $this->paged($auth, '/voices', ['voice_type' => 'private'], optional: true);

        $rows = [...$public, ...$private];

        return $this->unique(array_map(
            fn (array $row) => CatalogueEntry::fromRow($row, 'id', 'name', language: CatalogueEntry::text($row, 'language'), previewAudioViaApi: true),
            $rows,
        ));
    }

    /**
     * @param  list<CatalogueEntry|null>  $entries
     * @return list<CatalogueEntry>
     */
    private function unique(array $entries): array
    {
        $unique = [];

        foreach ($entries as $entry) {
            if ($entry !== null && ! isset($unique[$entry->id])) {
                $unique[$entry->id] = $entry;
            }
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function paged(ProviderAuth $auth, string $path, array $query = [], bool $optional = false): array
    {
        $rows = [];

        try {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $response = $this->api->orFail($this->api->call($auth, 'GET', $path.'?'.http_build_query($query + ['page' => $page, 'page_size' => self::PAGE_SIZE]), timeout: 15));

                $data = $response->json('data');
                $batch = is_array($data) && is_array($data['results'] ?? null) ? array_values(array_filter($data['results'], 'is_array')) : [];
                $rows = [...$rows, ...$batch];

                if ($batch === [] || ! is_string($data['next'] ?? null) || $data['next'] === '') {
                    break;
                }
            }
        } catch (ProviderException $failure) {
            if (! $optional) {
                throw $failure;
            }

            Log::warning('LiveAvatar optional catalogue list failed', $failure->context());
        }

        return $rows;
    }
}
