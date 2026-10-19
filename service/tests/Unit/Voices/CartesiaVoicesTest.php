<?php

use App\AvatarProviders\CatalogueEntry;
use App\AvatarProviders\ProviderException;
use App\Credentials\ProviderAuth;
use App\Voices\CartesiaVoices;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->auth = new ProviderAuth('SECRET_CARTESIA_KEY_1', 'acc_01');
    $this->vendor = new CartesiaVoices;
});

/**
 * @return list<array<string, mixed>>
 */
function cartesiaRows(): array
{
    return array_map(fn (CatalogueEntry $entry) => $entry->toArray(), (new CartesiaVoices)->voices(test()->auth));
}

describe('the vendor', function () {
    it('names itself, its model and what its sample says', function () {
        expect($this->vendor->id())->toBe('cartesia')
            ->and($this->vendor->model())->toBe('sonic-3')
            ->and($this->vendor->speaksPhrase())->toBeTrue();
    });
});

describe('voices', function () {
    it('exposes the id, the name and the language the vendor states, verbatim', function () {
        Http::fake(['api.cartesia.ai/voices*' => Http::response(['data' => [
            ['id' => 'c-en', 'name' => 'Barbershop Man', 'language' => 'en', 'gender' => 'masculine', 'description' => 'x'],
            ['id' => 'c-it', 'name' => 'Giulia', 'language' => 'it'],
            ['id' => 'c-none', 'name' => 'Nameless Language'],
        ], 'has_more' => false])]);

        expect(cartesiaRows())->toBe([
            (new CatalogueEntry('c-en', 'Barbershop Man', 'en', previewAudioViaApi: true))->toArray(),
            (new CatalogueEntry('c-it', 'Giulia', 'it', previewAudioViaApi: true))->toArray(),
            (new CatalogueEntry('c-none', 'Nameless Language', previewAudioViaApi: true))->toArray(),
        ]);
    });

    it('says every voice can be heard through the API and surfaces no file url, which needs the key', function () {
        Http::fake(['api.cartesia.ai/voices*' => Http::response(['data' => [
            ['id' => 'a', 'name' => 'A', 'preview_file_url' => 'https://files.cartesia.ai/files/SECRETFILE/download'],
            ['id' => 'b', 'name' => 'B'],
        ], 'has_more' => false])]);

        $rows = cartesiaRows();

        expect(array_column($rows, 'preview_audio_via_api'))->toBe([true, true])
            ->and(array_column($rows, 'preview_audio_url'))->toBe([null, null])
            ->and(json_encode($rows))->not->toContain('SECRETFILE')->not->toContain('files.cartesia.ai');
    });

    it('asks with the key, the version header and a page size of one hundred', function () {
        Http::fake(['api.cartesia.ai/voices*' => Http::response(['data' => [], 'has_more' => false])]);

        cartesiaRows();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.cartesia.ai/voices?')
            && str_contains($request->url(), 'limit=100')
            && $request->header('X-API-Key') === ['SECRET_CARTESIA_KEY_1']
            && $request->header('Cartesia-Version') === ['2025-04-16']);
    });

    it('follows the pagination until has_more is false', function () {
        Http::fake(['api.cartesia.ai/voices*' => Http::sequence()
            ->push(['data' => [['id' => 'a', 'name' => 'A']], 'has_more' => true, 'next_page' => 'a'])
            ->push(['data' => [['id' => 'b', 'name' => 'B']], 'has_more' => false])]);

        expect(array_column(cartesiaRows(), 'id'))->toBe(['a', 'b']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'starting_after=a'));
    });

    it('accepts the older answer, a bare list', function () {
        Http::fake(['api.cartesia.ai/voices*' => Http::response([['id' => 'a', 'name' => 'A'], 'junk'])]);

        expect(array_column(cartesiaRows(), 'id'))->toBe(['a']);
    });

    it('stops at the page bound', function () {
        Http::fake(['api.cartesia.ai/voices*' => Http::response(['data' => [['id' => 'a', 'name' => 'A']], 'has_more' => true, 'next_page' => 'a'])]);

        cartesiaRows();

        Http::assertSentCount(20);
    });

    it('raises a provider exception with the status and nothing else', function (int $status) {
        Http::fake(['*' => Http::response(['detail' => 'bad key SECRET_CARTESIA_KEY_1'], $status)]);

        try {
            cartesiaRows();
            $this->fail('A refused list must raise.');
        } catch (ProviderException $failure) {
            expect($failure->getMessage())->toBe("Provider request failed (status {$status})");
        }
    })->with([401, 403, 429, 500]);

    it('raises when the vendor cannot be reached or answers nonsense', function () {
        Http::fake(fn () => throw new ConnectionException('timed out SECRET_CARTESIA_KEY_1'));
        expect(fn () => cartesiaRows())->toThrow(ProviderException::class, '(no response)');

        Http::fake(['*' => Http::response('not json', 200)]);
        expect(fn () => cartesiaRows())->toThrow(ProviderException::class, '(no response)');
    });
});

describe('sample', function () {
    it('synthesizes the given phrase with the pinned model and returns the audio bytes', function () {
        Http::fake(['api.cartesia.ai/tts/bytes' => Http::response("ID3\x03FAKE-MP3", 200)]);

        $audio = $this->vendor->sample('cart-voice-1', 'it', 'Una frase.', $this->auth);

        expect($audio)->toBe("ID3\x03FAKE-MP3");
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.cartesia.ai/tts/bytes'
            && $request->method() === 'POST'
            && $request->header('X-API-Key') === ['SECRET_CARTESIA_KEY_1']
            && $request->header('Cartesia-Version') === ['2025-04-16']
            && $request->data() === [
                'model_id' => 'sonic-3',
                'transcript' => 'Una frase.',
                'voice' => ['mode' => 'id', 'id' => 'cart-voice-1'],
                'language' => 'it',
                'output_format' => ['container' => 'mp3', 'sample_rate' => 44100, 'bit_rate' => 128000],
            ]);
    });

    it('keeps the status of a failure, so a missing voice can be told from a failing vendor', function (int $status) {
        Http::fake(['*' => Http::response(['error' => 'SECRET_CARTESIA_KEY_1'], $status)]);

        try {
            $this->vendor->sample('v', 'it', 'x', $this->auth);
            $this->fail('A refused synthesis must raise.');
        } catch (ProviderException $failure) {
            expect($failure->status())->toBe($status)->and($failure->getMessage())->not->toContain('SECRET');
        }
    })->with([404, 401, 429, 500]);

    it('raises when the vendor cannot be reached', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 SECRET_CARTESIA_KEY_1'));

        expect(fn () => $this->vendor->sample('v', 'it', 'x', $this->auth))->toThrow(ProviderException::class, '(no response)');
    });

    it('refuses an answer over the cap', function () {
        config()->set('hr-avatar.voice_preview.max_bytes', 100);
        Http::fake(['api.cartesia.ai/tts/bytes' => Http::response(str_repeat('x', 101))]);

        expect(fn () => $this->vendor->sample('v', 'it', 'x', $this->auth))->toThrow(ProviderException::class, '(no response)');
    });

    it('accepts an answer of exactly the cap', function () {
        config()->set('hr-avatar.voice_preview.max_bytes', 100);
        Http::fake(['api.cartesia.ai/tts/bytes' => Http::response(str_repeat('x', 100))]);

        expect($this->vendor->sample('v', 'it', 'x', $this->auth))->toHaveLength(100);
    });

    it('refuses an empty answer', function () {
        Http::fake(['api.cartesia.ai/tts/bytes' => Http::response('')]);

        expect(fn () => $this->vendor->sample('v', 'it', 'x', $this->auth))->toThrow(ProviderException::class, '(no response)');
    });

    it('fails cleanly when the body breaks while it is read, with nothing leaked', function (Throwable $failure) {
        $logs = captureLogs();
        Http::fake(['api.cartesia.ai/tts/bytes' => function () use ($failure) {
            $body = FnStream::decorate(Utils::streamFor('partial'), [
                'read' => function () use ($failure): never {
                    throw $failure;
                },
                'eof' => fn (): bool => false,
            ]);

            return Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'audio/mpeg'], $body));
        }]);

        try {
            $this->vendor->sample('v', 'it', 'x', $this->auth);
            $this->fail('A broken body must raise.');
        } catch (ProviderException $raised) {
            expect($raised->getMessage().implode('', $logs->getArrayCopy()))->not->toContain('SECRET_CARTESIA_KEY_1')->not->toContain('mid-body');
        }
    })->with([
        'guzzle transfer error' => [fn () => new TransferException('mid-body SECRET_CARTESIA_KEY_1', new GuzzleHttp\Psr7\Request('POST', 'https://api.cartesia.ai/tts/bytes'))],
        'stream runtime error' => [fn () => new RuntimeException('mid-body SECRET_CARTESIA_KEY_1')],
    ]);
});
