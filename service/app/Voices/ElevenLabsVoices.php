<?php

namespace App\Voices;

use App\AvatarProviders\CatalogueEntry;
use App\AvatarProviders\ListBounds;
use App\Credentials\ProviderAuth;
use Illuminate\Support\Facades\Http;

/**
 * ElevenLabs as a voice vendor: its voice list (`GET /v2/voices`, paginated with `next_page_token`)
 * and a synthesized sample (`POST /v1/text-to-speech/{voice}`).
 *
 * The language is the one ElevenLabs states: the voice's `labels.language`, else the first of its
 * verified languages. It is never inferred from an accent or a name. The preview url is the one of the
 * verified entry in that language, else the voice's own.
 */
final class ElevenLabsVoices extends HttpVoiceVendor
{
    private const BASE_URL = 'https://api.elevenlabs.io';

    private const PAGE_SIZE = 100;

    /** Bounds the loop so a vendor that never ends the list cannot hold a worker. */
    private const MAX_PAGES = 20;

    public function id(): string
    {
        return 'elevenlabs';
    }

    public function model(): ?string
    {
        $model = config('hr-avatar.voice_preview.models.elevenlabs');

        return is_string($model) ? $model : null;
    }

    public function speaksPhrase(): bool
    {
        return true;
    }

    public function voices(ProviderAuth $auth): array
    {
        $entries = [];
        $token = null;
        $bounds = new ListBounds('elevenlabs:voices');
        $complete = false;

        for ($page = 0; $page < self::MAX_PAGES && ! $bounds->expired(); $page++) {
            $query = ['page_size' => self::PAGE_SIZE] + ($token === null ? [] : ['next_page_token' => $token]);
            $body = $this->json($this->send(fn () => Http::withHeaders(['xi-api-key' => $auth->apiKey()])->timeout($this->timeout())->get(self::BASE_URL.'/v2/voices', $query)));

            $rows = is_array($body['voices'] ?? null) ? array_values(array_filter($body['voices'], 'is_array')) : [];

            foreach ($rows as $row) {
                $entry = $this->entry($row);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            $next = $body['next_page_token'] ?? null;

            if (($body['has_more'] ?? false) !== true || ! is_string($next) || $next === '') {
                $complete = true;

                break;
            }

            $token = $next;
        }

        if (! $complete) {
            $bounds->cut();
        }

        return $entries;
    }

    public function sample(string $voiceId, string $language, string $phrase, ProviderAuth $auth): string
    {
        $url = self::BASE_URL.'/v1/text-to-speech/'.rawurlencode($voiceId).'?'.http_build_query(['output_format' => config('hr-avatar.voice_preview.output_formats.elevenlabs')]);

        return $this->boundedBody($this->send(fn () => Http::withHeaders(['xi-api-key' => $auth->apiKey(), 'Accept' => 'audio/mpeg'])->timeout($this->timeout())->withOptions(['stream' => true])->post($url, [
            'text' => $phrase,
            'model_id' => $this->model(),
            'language_code' => $language,
        ])));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function entry(array $row): ?CatalogueEntry
    {
        $labels = is_array($row['labels'] ?? null) ? $row['labels'] : [];
        /** @var list<array<string, mixed>> $verified */
        $verified = array_values(array_filter(is_array($row['verified_languages'] ?? null) ? $row['verified_languages'] : [], 'is_array'));

        $language = self::language($labels['language'] ?? null) ?? self::language($verified[0]['language'] ?? null);
        $own = array_find($verified, fn (array $entry) => $language !== null && self::language($entry['language'] ?? null) === $language);

        $entry = CatalogueEntry::fromRow($row, 'voice_id', 'name', language: $language);

        $previewUrl = CatalogueEntry::text($own ?? [], 'preview_url') ?? CatalogueEntry::text($row, 'preview_url');

        // A voice with no public sample can still be heard through the preview endpoint.
        return $entry === null ? null : new CatalogueEntry($entry->id, $entry->name, $language, previewAudioUrl: $previewUrl, previewAudioViaApi: $previewUrl === null);
    }
}
