<?php

use App\AvatarProviders\ProviderException;
use App\Credentials\ProviderAuth;
use App\Voices\VoicePreview;
use App\Voices\VoiceVendor;
use App\Voices\VoiceVendorRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// The vendors read the audio as a stream, which can be read once: a fake that answers more than one call is a
// closure, so each call gets a fresh response.
const PREVIEW_MP3 = "ID3\x03\x00\x00\x00\x00\x00\x00FAKE-MP3-BYTES";

beforeEach(function () {
    Cache::flush();
    $this->auth = new ProviderAuth('TEST_VENDOR_KEY_1', 'acc_01');
    $this->preview = app(VoicePreview::class);
});

function previewVendor(string $id): VoiceVendor
{
    return app(VoiceVendorRegistry::class)->get($id) ?? throw new LogicException("No vendor {$id}");
}

describe('which vendor speaks', function () {
    it('is the external engine when one is named, else the provider itself', function (string $provider, ?string $engine, ?string $expected) {
        expect(app(VoicePreview::class)->vendorFor($provider, $engine)?->id())->toBe($expected);
    })->with([
        'a vendor itself' => ['cartesia', null, 'cartesia'],
        'a vendor itself, other vendor engine' => ['cartesia', 'elevenlabs', 'elevenlabs'],
        'tavus through cartesia' => ['tavus', 'cartesia', 'cartesia'],
        'tavus through elevenlabs' => ['tavus', 'elevenlabs', 'elevenlabs'],
        'heygen on its own' => ['heygen', null, 'heygen'],
        'heygen through an external voice' => ['heygen', 'cartesia', 'cartesia'],
        'tavus stock voice' => ['tavus', null, null],
        'mock' => ['mock', null, null],
    ]);
});

describe('the phrase', function () {
    it('is the configured sentence of the language, never anything the caller sent', function (string $language) {
        Http::fake(['api.cartesia.ai/tts/bytes' => fn () => Http::response(PREVIEW_MP3)]);

        $this->preview->preview(previewVendor('cartesia'), 'v1', $language, $this->auth);

        Http::assertSent(fn (Request $request) => $request['transcript'] === config("hr-avatar.voice_preview.phrases.{$language}") && $request['language'] === $language);
    })->with(['it', 'en']);

    it('is neutral: it names no host product', function () {
        foreach (config()->array('hr-avatar.voice_preview.phrases') as $phrase) {
            expect(strtolower((string) $phrase))->not->toContain('interview')->not->toContain('candidate')->not->toContain('colloquio');
        }
    });

    it('refuses a language it has no sentence for, before any call', function () {
        Http::fake();

        expect(fn () => $this->preview->preview(previewVendor('cartesia'), 'v1', 'fr', $this->auth))->toThrow(InvalidArgumentException::class);
        Http::assertNothingSent();
    });
});

describe('the audio', function () {
    it('is returned with the type read from its bytes', function (string $bytes, string $type) {
        Http::fake(['api.cartesia.ai/tts/bytes' => fn () => Http::response($bytes)]);

        expect($this->preview->preview(previewVendor('cartesia'), 'v1', 'it', $this->auth))->toBe(['audio' => $bytes, 'content_type' => $type]);
    })->with([
        'mp3' => [PREVIEW_MP3, 'audio/mpeg'],
        'wav' => ["RIFF\x24\x00\x00\x00WAVEfmt FAKE", 'audio/wav'],
        'ogg' => ["OggS\x00FAKE", 'audio/ogg'],
        'anything else with a binary header' => ["\xFF\xFB\x90\x00FAKE", 'audio/mpeg'],
    ]);

    // Review rv19 R3-002: a success status with a text body (a JSON or HTML error envelope, a plain-text message) is not
    // audio, and would otherwise be cached for days and served to every later preview of that voice.
    it('is refused when it is text, whatever the vendor, and never cached', function (string $body) {
        Http::fake(['api.cartesia.ai/tts/bytes' => Http::sequence()->push($body)->push(PREVIEW_MP3)]);

        expect(fn () => $this->preview->preview(previewVendor('cartesia'), 'v1', 'it', $this->auth))->toThrow(ProviderException::class, '(no response)');
        expect($this->preview->preview(previewVendor('cartesia'), 'v1', 'it', $this->auth)['audio'])->toBe(PREVIEW_MP3);
        Http::assertSentCount(2);
    })->with([
        'a JSON error' => ['{"error":"quota exceeded"}'],
        'an HTML page' => ["<html>\n<body>Bad gateway</body></html>"],
        'a plain message' => ["Too many requests\r\n"],
        'a message with accented letters' => ['{"errore":"Quota già esaurita, riprova più tardi"}'],
        'a message that starts with a letter outside ASCII' => ['È stato superato il limite di richieste'],
    ]);

    it('is refused when empty or larger than the cap whatever the vendor, and never cached', function (string $audio) {
        config()->set('hr-avatar.voice_preview.max_bytes', 100);
        Http::fake(['api.liveavatar.com/v1/voices/*/preview' => Http::sequence()
            ->push(['data' => ['audio_base64' => base64_encode($audio)]])
            ->push(['data' => ['audio_base64' => base64_encode(PREVIEW_MP3)]])]);

        expect(fn () => $this->preview->preview(previewVendor('heygen'), 'hv1', 'it', $this->auth))->toThrow(ProviderException::class, '(no response)');
        expect($this->preview->preview(previewVendor('heygen'), 'hv1', 'it', $this->auth)['audio'])->toBe(PREVIEW_MP3);
        Http::assertSentCount(2);
    })->with(['oversize' => [str_repeat('x', 101)], 'empty' => ['']]);

    it('is refused when a streaming vendor answers more than the cap, and never cached', function () {
        config()->set('hr-avatar.voice_preview.max_bytes', 100);
        Http::fake(['api.cartesia.ai/tts/bytes' => Http::sequence()->push(str_repeat('x', 101))->push(PREVIEW_MP3)]);

        expect(fn () => $this->preview->preview(previewVendor('cartesia'), 'v1', 'it', $this->auth))->toThrow(ProviderException::class, '(no response)');
        expect($this->preview->preview(previewVendor('cartesia'), 'v1', 'it', $this->auth)['audio'])->toBe(PREVIEW_MP3);
    });

    it('is accepted at exactly the cap', function () {
        config()->set('hr-avatar.voice_preview.max_bytes', 16);
        Http::fake(['api.cartesia.ai/tts/bytes' => fn () => Http::response("ID3\x03".str_repeat('x', 12))]);

        expect($this->preview->preview(previewVendor('cartesia'), 'v1', 'it', $this->auth)['audio'])->toHaveLength(16);
    });
});

describe('the cache', function () {
    it('answers a repeat of the same voice from the cache, with no vendor call', function () {
        Http::fake(['api.cartesia.ai/tts/bytes' => fn () => Http::response(PREVIEW_MP3)]);

        $first = $this->preview->preview(previewVendor('cartesia'), 'cached-voice', 'it', $this->auth);
        $second = $this->preview->preview(previewVendor('cartesia'), 'cached-voice', 'it', $this->auth);

        expect($second)->toBe($first);
        Http::assertSentCount(1);
    });

    it('separates vendor, voice, language and phrase version', function () {
        Http::fake(['*' => fn () => Http::response(PREVIEW_MP3)]);

        $this->preview->preview(previewVendor('cartesia'), 'a', 'it', $this->auth);
        $this->preview->preview(previewVendor('cartesia'), 'b', 'it', $this->auth);
        $this->preview->preview(previewVendor('cartesia'), 'a', 'en', $this->auth);
        $this->preview->preview(previewVendor('elevenlabs'), 'a', 'it', $this->auth);
        config()->set('hr-avatar.voice_preview.phrase_version', 'bumped');
        $this->preview->preview(previewVendor('cartesia'), 'a', 'it', $this->auth);

        Http::assertSentCount(5);
    });

    it('separates the model, so a model change is not served the old audio', function () {
        Http::fake(['*' => fn () => Http::response(PREVIEW_MP3)]);

        $this->preview->preview(previewVendor('cartesia'), 'a', 'it', $this->auth);
        config()->set('hr-avatar.voice_preview.models.cartesia', 'sonic-3.5');
        $this->preview->preview(previewVendor('cartesia'), 'a', 'it', $this->auth);

        Http::assertSentCount(2);
    });

    it('never serves audio made with one account\'s key to another account', function () {
        Http::fake(['*' => fn () => Http::response(PREVIEW_MP3)]);

        $this->preview->preview(previewVendor('cartesia'), 'private-voice', 'it', new ProviderAuth('key-of-account-a-111', 'acc_a'));
        $this->preview->preview(previewVendor('cartesia'), 'private-voice', 'it', new ProviderAuth('key-of-account-b-222', 'acc_b'));

        Http::assertSentCount(2);
    });

    it('keeps the platform key\'s audio shared between the accounts that use it', function () {
        Http::fake(['*' => fn () => Http::response(PREVIEW_MP3)]);

        $this->preview->preview(previewVendor('cartesia'), 'a', 'it', new ProviderAuth('platform-key-1234', ProviderAuth::PLATFORM));
        $this->preview->preview(previewVendor('cartesia'), 'a', 'it', new ProviderAuth('platform-key-1234', ProviderAuth::PLATFORM));

        Http::assertSentCount(1);
    });

    it('does not cache a failure: the next call asks again', function () {
        Http::fake(['api.cartesia.ai/tts/bytes' => Http::sequence()->push('boom', 500)->push(PREVIEW_MP3)]);

        expect(fn () => $this->preview->preview(previewVendor('cartesia'), 'flaky', 'it', $this->auth))->toThrow(ProviderException::class);
        $this->preview->preview(previewVendor('cartesia'), 'flaky', 'it', $this->auth);

        Http::assertSentCount(2);
    });

    it('serves the generic sample of a vendor that has only one for every language from one entry', function () {
        Http::fake(['api.liveavatar.com/v1/voices/*/preview' => Http::response(['data' => ['audio_base64' => base64_encode(PREVIEW_MP3)]])]);

        $this->preview->preview(previewVendor('heygen'), 'hv1', 'it', $this->auth);
        $this->preview->preview(previewVendor('heygen'), 'hv1', 'en', $this->auth);

        Http::assertSentCount(1);
    });

    it('expires after the configured lifetime', function () {
        config()->set('hr-avatar.voice_preview.cache_seconds', 60);
        Http::fake(['api.cartesia.ai/tts/bytes' => fn () => Http::response(PREVIEW_MP3)]);

        $this->preview->preview(previewVendor('cartesia'), 'v', 'it', $this->auth);
        $this->travel(59)->seconds();
        $this->preview->preview(previewVendor('cartesia'), 'v', 'it', $this->auth);
        Http::assertSentCount(1);

        $this->travel(2)->seconds();
        $this->preview->preview(previewVendor('cartesia'), 'v', 'it', $this->auth);
        Http::assertSentCount(2);
    });
});
