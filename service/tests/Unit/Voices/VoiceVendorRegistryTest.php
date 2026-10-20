<?php

use App\AvatarProviders\LiveAvatar\LiveAvatarVoices;
use App\Voices\CartesiaVoices;
use App\Voices\ElevenLabsVoices;
use App\Voices\VoiceVendorRegistry;

it('resolves the three vendors to their classes', function (string $id, string $class) {
    expect(app(VoiceVendorRegistry::class)->get($id))->toBeInstanceOf($class);
})->with([
    'cartesia' => ['cartesia', CartesiaVoices::class],
    'elevenlabs' => ['elevenlabs', ElevenLabsVoices::class],
    'heygen' => ['heygen', LiveAvatarVoices::class],
]);

it('answers null for a provider that is not a voice vendor', function (?string $id) {
    expect(app(VoiceVendorRegistry::class)->get($id))->toBeNull();
})->with(['tavus', 'mock', 'nope', '', null]);

it('lists the vendors in configuration order', function () {
    expect(app(VoiceVendorRegistry::class)->ids())->toBe(['cartesia', 'elevenlabs', 'heygen']);
});

it('reads only the configuration: a vendor added there is found', function () {
    config()->set('hr-avatar.voice_vendors', ['cartesia' => CartesiaVoices::class]);

    expect(app(VoiceVendorRegistry::class)->ids())->toBe(['cartesia'])->and(app(VoiceVendorRegistry::class)->get('elevenlabs'))->toBeNull();
});

it('names the id each vendor registers under', function () {
    foreach (app(VoiceVendorRegistry::class)->ids() as $id) {
        expect(app(VoiceVendorRegistry::class)->get($id)?->id())->toBe($id);
    }
});
