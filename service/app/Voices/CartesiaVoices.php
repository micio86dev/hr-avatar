<?php

namespace App\Voices;

use App\AvatarProviders\CatalogueEntry;
use App\Credentials\ProviderAuth;
use Illuminate\Support\Facades\Http;

/**
 * Cartesia as a voice vendor: its voice list (`GET /voices`, paginated with `starting_after`) and a
 * synthesized sample (`POST /tts/bytes`).
 *
 * The language is the one Cartesia states, never inferred. Cartesia's own sample files need the key to
 * download, so no url is surfaced: every voice says it can be heard through `POST /v1/voice-preview`.
 */
final class CartesiaVoices extends HttpVoiceVendor
{
    private const BASE_URL = 'https://api.cartesia.ai';

    private const PAGE_SIZE = 100;

    /** Bounds the loop so a vendor that never ends the list cannot hold a worker. */
    private const MAX_PAGES = 20;

    public function id(): string
    {
        return 'cartesia';
    }

    public function model(): ?string
    {
        $model = config('hr-avatar.voice_preview.models.cartesia');

        return is_string($model) ? $model : null;
    }

    public function speaksPhrase(): bool
    {
        return true;
    }

    public function voices(ProviderAuth $auth): array
    {
        $entries = [];
        $startingAfter = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['limit' => self::PAGE_SIZE] + ($startingAfter === null ? [] : ['starting_after' => $startingAfter]);
            $body = $this->json($this->send(fn () => Http::withHeaders($this->headers($auth))->timeout($this->timeout())->get(self::BASE_URL.'/voices', $query)));

            // Older API versions answer a bare list.
            $isList = array_is_list($body);
            $batch = $isList ? $body : ($body['data'] ?? []);
            $rows = is_array($batch) ? array_values(array_filter($batch, 'is_array')) : [];

            foreach ($rows as $row) {
                $entry = CatalogueEntry::fromRow($row, 'id', 'name', language: self::language($row['language'] ?? null), previewAudioViaApi: true);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            $next = $isList ? null : ($body['next_page'] ?? null);

            if ($isList || ($body['has_more'] ?? false) !== true || ! is_string($next) || $next === '') {
                break;
            }

            $startingAfter = $next;
        }

        return $entries;
    }

    public function sample(string $voiceId, string $language, string $phrase, ProviderAuth $auth): string
    {
        return $this->boundedBody($this->send(fn () => Http::withHeaders($this->headers($auth))->timeout($this->timeout())->withOptions(['stream' => true])->post(self::BASE_URL.'/tts/bytes', [
            'model_id' => $this->model(),
            'transcript' => $phrase,
            'voice' => ['mode' => 'id', 'id' => $voiceId],
            'language' => $language,
            'output_format' => config('hr-avatar.voice_preview.output_formats.cartesia'),
        ])));
    }

    /**
     * @return array<string, string>
     */
    public static function headers(ProviderAuth $auth): array
    {
        return ['X-API-Key' => $auth->apiKey(), 'Cartesia-Version' => (string) config('hr-avatar.voice_preview.cartesia_version')];
    }

    private static function language(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : null;
    }
}
