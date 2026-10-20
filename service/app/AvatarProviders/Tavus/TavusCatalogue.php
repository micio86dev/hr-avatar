<?php

namespace App\AvatarProviders\Tavus;

use App\AvatarProviders\CatalogueEntry;
use App\AvatarProviders\ListBounds;
use App\AvatarProviders\ProviderException;
use App\Credentials\ProviderAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Lists what a Tavus account can pick from: its replicas (Tavus now calls them faces) and its
 * personas (pals), as the shared CatalogueEntry. Tavus states no language for either, so it is
 * always null. A persona's system prompt and layers are never read. A face that states a status
 * other than `completed` (still training, errored) is not offered, since it would fail the
 * conversation later; a row that states none is offered.
 *
 * Reads inventory only; it never touches a conversation (see TavusConcurrencyGuard). A failure
 * carries the status and nothing of what Tavus wrote.
 */
final class TavusCatalogue
{
    private const BASE_URL = 'https://tavusapi.com/v2';

    /** Tavus's own maximum; its default of ten would silently cut the list. */
    private const PAGE_SIZE = 100;

    /** Bounds the loop so a provider that never ends the list cannot hold a worker. */
    private const MAX_PAGES = 20;

    private const TIMEOUT_SECONDS = 15;

    /**
     * @return list<CatalogueEntry>
     *
     * @throws ProviderException
     * @throws InvalidArgumentException for a resource Tavus does not list
     */
    public function list(string $resource, ProviderAuth $auth): array
    {
        return match ($resource) {
            'replica' => $this->entries($this->paged($auth, '/faces', ['verbose' => 'true']), ['face_id', 'replica_id'], ['face_name', 'replica_name'], completedOnly: true),
            'pal' => $this->entries($this->paged($auth, '/pals', []), ['pal_id', 'persona_id'], ['pal_name', 'persona_name']),
            default => throw new InvalidArgumentException('Tavus has no such catalogue resource.'),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $idKeys
     * @param  list<string>  $nameKeys
     * @return list<CatalogueEntry>
     */
    private function entries(array $rows, array $idKeys, array $nameKeys, bool $completedOnly = false): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if ($completedOnly && ($row['status'] ?? 'completed') !== 'completed') {
                continue;
            }

            $entry = CatalogueEntry::fromRow(
                $row, $idKeys, $nameKeys,
                previewImageUrl: CatalogueEntry::text($row, 'thumbnail_image_url'),
                previewVideoUrl: CatalogueEntry::text($row, 'thumbnail_video_url'),
            );

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function paged(ProviderAuth $auth, string $path, array $query): array
    {
        $rows = [];
        $bounds = new ListBounds('tavus:'.ltrim($path, '/'));
        $complete = false;

        for ($page = 1; $page <= self::MAX_PAGES && ! $bounds->expired(); $page++) {
            $body = $this->request($auth, $path, $query + ['limit' => self::PAGE_SIZE, 'page' => $page]);

            $batch = is_array($body['data'] ?? null) ? array_values(array_filter($body['data'], 'is_array')) : [];
            $rows = [...$rows, ...$batch];
            $total = $body['total_count'] ?? null;

            if ($batch === [] || count($batch) < self::PAGE_SIZE || (is_int($total) && count($rows) >= $total)) {
                $complete = true;

                break;
            }
        }

        if (! $complete) {
            $bounds->cut();
        }

        return $rows;
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array<mixed>
     */
    private function request(ProviderAuth $auth, string $path, array $query): array
    {
        try {
            $response = Http::withHeaders(['x-api-key' => $auth->apiKey()])->timeout(self::TIMEOUT_SECONDS)->get(self::BASE_URL.$path, $query);
        } catch (ConnectionException) {
            throw ProviderException::noResponse();
        }

        if (! $response->successful()) {
            throw ProviderException::fromStatus($response->status());
        }

        $body = $response->json();

        return is_array($body) ? $body : throw ProviderException::noResponse();
    }
}
