<?php

use App\AvatarProviders\CatalogueEntry;

it('is exactly the normalized shape and nothing a provider sent', function () {
    $entry = new CatalogueEntry(
        id: 'v1', name: 'Marco', language: 'it', previewAudioUrl: 'https://cdn.example/v1.mp3',
        previewAudioViaApi: false, previewImageUrl: 'https://cdn.example/v1.png', previewVideoUrl: 'https://cdn.example/v1.mp4',
    );

    expect($entry->toArray())->toBe([
        'id' => 'v1',
        'name' => 'Marco',
        'language' => 'it',
        'preview_audio_url' => 'https://cdn.example/v1.mp3',
        'preview_audio_via_api' => false,
        'preview_image_url' => 'https://cdn.example/v1.png',
        'preview_video_url' => 'https://cdn.example/v1.mp4',
    ]);
});

it('defaults every optional member to null or false', function () {
    expect((new CatalogueEntry('a', 'A'))->toArray())->toBe([
        'id' => 'a', 'name' => 'A', 'language' => null, 'preview_audio_url' => null,
        'preview_audio_via_api' => false, 'preview_image_url' => null, 'preview_video_url' => null,
    ]);
});
