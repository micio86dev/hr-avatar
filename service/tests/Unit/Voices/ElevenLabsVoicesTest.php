<?php

use App\AvatarProviders\CatalogueEntry;
use App\AvatarProviders\ProviderException;
use App\Credentials\ProviderAuth;
use App\Voices\ElevenLabsVoices;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->auth = new ProviderAuth('SECRET_ELEVENLABS_KEY_2', 'acc_01');
    $this->vendor = new ElevenLabsVoices;
});

/**
 * @return list<array<string, mixed>>
 */
function elevenLabsRows(): array
{
    return array_map(fn (CatalogueEntry $entry) => $entry->toArray(), (new ElevenLabsVoices)->voices(test()->auth));
}

describe('the vendor', function () {
    it('names itself, its model and what its sample says', function () {
        expect($this->vendor->id())->toBe('elevenlabs')
            ->and($this->vendor->model())->toBe('eleven_multilingual_v2')
            ->and($this->vendor->speaksPhrase())->toBeTrue();
    });
});

describe('voices', function () {
    it('reads the language from the label, else from the first verified language, and the preview of that language', function () {
        Http::fake(['api.elevenlabs.io/v2/voices*' => Http::response(['voices' => [
            [
                'voice_id' => 'e-label', 'name' => 'Aria', 'preview_url' => 'https://cdn.example/aria.mp3',
                'labels' => ['language' => 'en', 'accent' => 'american'],
                'verified_languages' => [['language' => 'en', 'preview_url' => 'https://cdn.example/aria-en.mp3'], ['language' => 'it', 'preview_url' => 'https://cdn.example/aria-it.mp3']],
            ],
            [
                'voice_id' => 'e-verified', 'name' => 'Chiara', 'labels' => ['gender' => 'female'],
                'verified_languages' => [['language' => 'it', 'locale' => 'it-IT', 'preview_url' => 'https://cdn.example/chiara-it.mp3']],
            ],
            ['voice_id' => 'e-plain', 'name' => 'Aaron', 'preview_url' => 'https://cdn.example/aaron.mp3'],
            ['voice_id' => 'e-bare', 'name' => 'Bare'],
        ], 'has_more' => false])]);

        expect(elevenLabsRows())->toBe([
            (new CatalogueEntry('e-label', 'Aria', 'en', 'https://cdn.example/aria-en.mp3'))->toArray(),
            (new CatalogueEntry('e-verified', 'Chiara', 'it', 'https://cdn.example/chiara-it.mp3'))->toArray(),
            (new CatalogueEntry('e-plain', 'Aaron', null, 'https://cdn.example/aaron.mp3'))->toArray(),
            (new CatalogueEntry('e-bare', 'Bare', previewAudioViaApi: true))->toArray(),
        ]);
    });

    it('lowercases the language and never infers it from an accent or a name', function () {
        Http::fake(['api.elevenlabs.io/v2/voices*' => Http::response(['voices' => [
            ['voice_id' => 'a', 'name' => 'Giulia Italiana', 'labels' => ['accent' => 'Italian', 'language' => ' EN ']],
            ['voice_id' => 'b', 'name' => 'Marco', 'labels' => ['accent' => 'Italian']],
        ], 'has_more' => false])]);

        expect(array_column(elevenLabsRows(), 'language'))->toBe(['en', null]);
    });

    it('asks with the key and a page size of one hundred', function () {
        Http::fake(['api.elevenlabs.io/v2/voices*' => Http::response(['voices' => [], 'has_more' => false])]);

        elevenLabsRows();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), 'page_size=100') && $request->header('xi-api-key') === ['SECRET_ELEVENLABS_KEY_2']);
    });

    it('follows next_page_token', function () {
        Http::fake(['api.elevenlabs.io/v2/voices*' => Http::sequence()
            ->push(['voices' => [['voice_id' => 'a', 'name' => 'A']], 'has_more' => true, 'next_page_token' => 'tok'])
            ->push(['voices' => [['voice_id' => 'b', 'name' => 'B']], 'has_more' => false])]);

        expect(array_column(elevenLabsRows(), 'id'))->toBe(['a', 'b']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'next_page_token=tok'));
    });

    it('stops at the page bound and ignores rows that are not objects', function () {
        Http::fake(['api.elevenlabs.io/v2/voices*' => Http::response(['voices' => ['junk', ['voice_id' => 'a', 'name' => 'A']], 'has_more' => true, 'next_page_token' => 'tok'])]);

        elevenLabsRows();

        Http::assertSentCount(20);
    });

    it('raises a provider exception with the status only, and when the vendor cannot be reached', function () {
        Http::fake(['*' => Http::response(['detail' => 'SECRET_ELEVENLABS_KEY_2'], 401)]);
        expect(fn () => elevenLabsRows())->toThrow(ProviderException::class, 'Provider request failed (status 401)');

        Http::fake(fn () => throw new ConnectionException('SECRET_ELEVENLABS_KEY_2'));
        expect(fn () => elevenLabsRows())->toThrow(ProviderException::class, '(no response)');

        Http::fake(['*' => Http::response('not json', 200)]);
        expect(fn () => elevenLabsRows())->toThrow(ProviderException::class, '(no response)');
    });
});

describe('sample', function () {
    it('synthesizes the given phrase with the pinned model and the language code', function () {
        Http::fake(['api.elevenlabs.io/v1/text-to-speech/*' => Http::response("ID3\x03FAKE-MP3", 200)]);

        $audio = $this->vendor->sample('El3ven_Voice-9', 'en', 'A phrase.', $this->auth);

        expect($audio)->toBe("ID3\x03FAKE-MP3");
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.elevenlabs.io/v1/text-to-speech/El3ven_Voice-9?')
            && str_contains($request->url(), 'output_format=mp3_44100_128')
            && $request->method() === 'POST'
            && $request->header('xi-api-key') === ['SECRET_ELEVENLABS_KEY_2']
            && $request->header('Accept') === ['audio/mpeg']
            && $request->data() === ['text' => 'A phrase.', 'model_id' => 'eleven_multilingual_v2', 'language_code' => 'en']);
    });

    it('does not put the voice id in the path unescaped', function () {
        Http::fake(['api.elevenlabs.io/*' => Http::response('x', 200)]);

        $this->vendor->sample('a/../b?c', 'en', 'x', $this->auth);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.elevenlabs.io/v1/text-to-speech/a%2F..%2Fb%3Fc?'));
    });

    it('refuses an answer over the cap, an empty one, and accepts one of exactly the cap', function (string $body, bool $accepted) {
        config()->set('hr-avatar.voice_preview.max_bytes', 100);
        Http::fake(['api.elevenlabs.io/v1/text-to-speech/*' => Http::response($body)]);

        $accepted
            ? expect($this->vendor->sample('v', 'en', 'x', $this->auth))->toHaveLength(100)
            : expect(fn () => $this->vendor->sample('v', 'en', 'x', $this->auth))->toThrow(ProviderException::class, '(no response)');
    })->with([
        'over' => [str_repeat('x', 101), false],
        'empty' => ['', false],
        'exact' => [str_repeat('x', 100), true],
    ]);

    it('keeps the status of a failure and raises when the vendor cannot be reached', function () {
        Http::fake(['*' => Http::response(['error' => 'SECRET_ELEVENLABS_KEY_2'], 404)]);
        try {
            $this->vendor->sample('v', 'en', 'x', $this->auth);
            $this->fail('A refused synthesis must raise.');
        } catch (ProviderException $failure) {
            expect($failure->status())->toBe(404);
        }

        Http::fake(fn () => throw new ConnectionException('SECRET_ELEVENLABS_KEY_2'));
        expect(fn () => $this->vendor->sample('v', 'en', 'x', $this->auth))->toThrow(ProviderException::class, '(no response)');
    });
});
