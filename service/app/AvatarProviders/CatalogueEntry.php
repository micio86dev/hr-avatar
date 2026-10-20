<?php

namespace App\AvatarProviders;

/**
 * One item of a provider's inventory (a voice, an avatar, a replica, a persona) in the single shape
 * the API publishes. Nothing a provider sent beyond these members survives, so a raw provider field
 * (a system prompt, a layer, a key) cannot reach a response.
 *
 * `language` is set only when the provider states it, never inferred from a name. A voice sample that
 * has no public url but can be fetched through `POST /v1/voice-preview` says so with `previewAudioViaApi`.
 */
final readonly class CatalogueEntry
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $language = null,
        public ?string $previewAudioUrl = null,
        public bool $previewAudioViaApi = false,
        public ?string $previewImageUrl = null,
        public ?string $previewVideoUrl = null,
    ) {}

    /**
     * @return array{id: string, name: string, language: string|null, preview_audio_url: string|null, preview_audio_via_api: bool, preview_image_url: string|null, preview_video_url: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'language' => $this->language,
            'preview_audio_url' => $this->previewAudioUrl,
            'preview_audio_via_api' => $this->previewAudioViaApi,
            'preview_image_url' => $this->previewImageUrl,
            'preview_video_url' => $this->previewVideoUrl,
        ];
    }

    /**
     * An entry from a provider's row, or null when the row has no usable id (an item that cannot be
     * chosen is not offered).
     *
     * @param  array<string, mixed>  $row
     * @param  string|list<string>  $idKeys  the keys the id may be under, in order
     * @param  string|list<string>  $nameKeys
     */
    public static function fromRow(array $row, string|array $idKeys, string|array $nameKeys, ?string $language = null, ?string $previewImageUrl = null, ?string $previewVideoUrl = null, bool $previewAudioViaApi = false): ?self
    {
        $id = self::text($row, $idKeys);

        return $id === null ? null : new self(
            $id,
            self::text($row, $nameKeys) ?? '',
            $language,
            previewAudioViaApi: $previewAudioViaApi,
            previewImageUrl: $previewImageUrl,
            previewVideoUrl: $previewVideoUrl,
        );
    }

    /**
     * The first non-empty string under any of the keys.
     *
     * @param  array<string, mixed>  $row
     * @param  string|list<string>  $keys
     */
    public static function text(array $row, string|array $keys): ?string
    {
        foreach ((array) $keys as $key) {
            $value = $row[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
