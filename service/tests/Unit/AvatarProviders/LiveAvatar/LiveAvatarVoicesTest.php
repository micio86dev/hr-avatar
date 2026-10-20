<?php

use App\AvatarProviders\LiveAvatar\LiveAvatarVoices;
use App\AvatarProviders\ProviderException;
use App\Credentials\ProviderAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->auth = new ProviderAuth('SECRET_LA_KEY_3', 'acc_01');
    $this->vendor = app(LiveAvatarVoices::class);
});

describe('the vendor', function () {
    it('names itself, and says its sample is the vendor\'s own clip, not the phrase', function () {
        expect($this->vendor->id())->toBe('heygen')
            ->and($this->vendor->model())->toBeNull()
            ->and($this->vendor->speaksPhrase())->toBeFalse();
    });

    it('lists the voices the LiveAvatar catalogue lists', function () {
        Http::fake(['api.liveavatar.com/v1/voices*' => Http::response(['data' => ['next' => null, 'results' => [['id' => 'hv1', 'name' => 'One', 'language' => 'en']]]])]);

        $voices = $this->vendor->voices($this->auth);

        expect(array_map(fn ($entry) => $entry->id, $voices))->toBe(['hv1']);
    });
});

describe('sample', function () {
    it('fetches the vendor\'s own clip and decodes the base64 audio', function () {
        Http::fake(['api.liveavatar.com/v1/voices/*/preview' => Http::response(['code' => 1000, 'data' => ['audio_base64' => base64_encode("ID3\x03FAKE-MP3")]])]);

        $audio = $this->vendor->sample('8c0d1f4e-1111-2222-3333-444455556666', 'it', 'ignored phrase', $this->auth);

        expect($audio)->toBe("ID3\x03FAKE-MP3");
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.liveavatar.com/v1/voices/8c0d1f4e-1111-2222-3333-444455556666/preview'
            && $request->header('X-API-KEY') === ['SECRET_LA_KEY_3']);
    });

    it('tolerates a data uri prefix', function () {
        Http::fake(['api.liveavatar.com/v1/voices/*/preview' => Http::response(['data' => ['audio_base64' => 'data:audio/mpeg;base64,'.base64_encode('BYTES')]])]);

        expect($this->vendor->sample('hv1', 'en', 'x', $this->auth))->toBe('BYTES');
    });

    it('raises on an answer it cannot decode', function (mixed $data) {
        Http::fake(['api.liveavatar.com/v1/voices/*/preview' => Http::response(['data' => $data])]);

        expect(fn () => $this->vendor->sample('hv1', 'en', 'x', $this->auth))->toThrow(ProviderException::class, '(no response)');
    })->with([
        'not base64' => [['audio_base64' => '%%%not base64%%%']],
        'not text' => [['audio_base64' => 5]],
        'no audio' => [['other' => 'x']],
        'no data' => [null],
    ]);

    it('keeps the status of a failure', function () {
        Http::fake(['*' => Http::response(['message' => 'SECRET_LA_KEY_3'], 404)]);

        try {
            $this->vendor->sample('hv1', 'en', 'x', $this->auth);
            $this->fail('A refused sample must raise.');
        } catch (ProviderException $failure) {
            expect($failure->status())->toBe(404)->and($failure->getMessage())->not->toContain('SECRET');
        }
    });

    it('does not put the voice id in the path unescaped', function () {
        Http::fake(['api.liveavatar.com/*' => Http::response(['data' => ['audio_base64' => base64_encode('x')]])]);

        $this->vendor->sample('a/../b?c', 'en', 'x', $this->auth);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.liveavatar.com/v1/voices/a%2F..%2Fb%3Fc/preview');
    });
});
