<?php

use App\Http\Requests\VoicePreviewRequest;
use App\Models\Account;
use App\Models\ProviderCredential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;

const ENDPOINT_MP3 = "ID3\x03\x00\x00\x00\x00\x00\x00FAKE-MP3-BYTES";

beforeEach(function () {
    Cache::flush();
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['avatar_configs:read']]);
    [, $this->noScope] = issueKey($this->account, ['scopes' => ['credentials:manage']]);
    foreach (['tavus', 'heygen', 'cartesia', 'elevenlabs'] as $provider) {
        config()->set("hr-avatar.platform_keys.{$provider}", null);
    }
});

/**
 * @param  array<string, mixed>  $body
 */
function previewPost(array $body, ?string $raw = null): TestResponse
{
    return test()->postJson('/v1/voice-preview', $body, ['Authorization' => 'Bearer '.($raw ?? test()->raw)]);
}

function previewKey(string $provider, string $key): void
{
    inAccount(test()->account, fn () => ProviderCredential::create(['provider' => $provider, 'api_key' => $key]));
}

describe('a preview', function () {
    it('answers the audio as base64 with its type', function () {
        previewKey('cartesia', 'TEST_CARTESIA_KEY');
        Http::fake(['api.cartesia.ai/tts/bytes' => fn () => Http::response(ENDPOINT_MP3)]);

        $response = previewPost(['provider' => 'cartesia', 'voice_id' => 'cart-voice-1', 'tts_engine' => null, 'language' => 'it'])->assertOk();

        expect(array_keys($response->json()))->toBe(['content_type', 'audio_base64'])
            ->and($response->json('content_type'))->toBe('audio/mpeg')
            ->and(base64_decode($response->json('audio_base64'), true))->toBe(ENDPOINT_MP3)
            ->and($response->getContent())->not->toContain('TEST_CARTESIA_KEY');
    });

    it('speaks the configured sentence of the language through the engine a Tavus voice is routed through', function (string $engine, string $host) {
        previewKey($engine, 'TEST_VENDOR_KEY');
        Http::fake(['*' => fn () => Http::response(ENDPOINT_MP3)]);

        previewPost(['provider' => 'tavus', 'voice_id' => 'ext-voice', 'tts_engine' => $engine, 'language' => 'en'])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), $host)
            && in_array(config('hr-avatar.voice_preview.phrases.en'), [$request['transcript'] ?? null, $request['text'] ?? null], true));
    })->with([['cartesia', 'api.cartesia.ai'], ['elevenlabs', 'api.elevenlabs.io']]);

    it('returns the provider\'s own sample for a LiveAvatar voice', function () {
        previewKey('heygen', 'TEST_LA_KEY');
        Http::fake(['api.liveavatar.com/v1/voices/*/preview' => Http::response(['data' => ['audio_base64' => base64_encode(ENDPOINT_MP3)]])]);

        $response = previewPost(['provider' => 'heygen', 'voice_id' => '8c0d1f4e-1111-2222-3333-444455556666', 'tts_engine' => null, 'language' => 'it'])->assertOk();

        expect(base64_decode($response->json('audio_base64'), true))->toBe(ENDPOINT_MP3);
        Http::assertSent(fn (Request $request) => $request->header('X-API-KEY') === ['TEST_LA_KEY']);
    });

    it('uses the platform key when the account has none', function () {
        config()->set('hr-avatar.platform_keys.cartesia', 'PLATFORM_CARTESIA_KEY');
        Http::fake(['api.cartesia.ai/tts/bytes' => fn () => Http::response(ENDPOINT_MP3)]);

        previewPost(['provider' => 'cartesia', 'voice_id' => 'v1', 'language' => 'it'])->assertOk();

        Http::assertSent(fn (Request $request) => $request->header('X-API-Key') === ['PLATFORM_CARTESIA_KEY']);
    });

    it('treats the engine as optional', function () {
        previewKey('cartesia', 'k');
        Http::fake(['*' => fn () => Http::response(ENDPOINT_MP3)]);

        previewPost(['provider' => 'cartesia', 'voice_id' => 'v1', 'language' => 'it'])->assertOk();
    });

    it('is served from the cache the second time, with no provider call', function () {
        previewKey('cartesia', 'k');
        Http::fake(['*' => fn () => Http::response(ENDPOINT_MP3)]);
        $body = ['provider' => 'cartesia', 'voice_id' => 'cached-voice', 'language' => 'it'];

        $first = previewPost($body)->assertOk();
        $second = previewPost($body)->assertOk();

        expect($second->json())->toBe($first->json());
        Http::assertSentCount(1);
    });
});

describe('what the caller may not choose', function () {
    it('rejects a body key that would choose the text', function (string $key) {
        previewKey('cartesia', 'k');
        Http::fake();

        $response = previewPost(['provider' => 'cartesia', 'voice_id' => 'v1', 'language' => 'it', $key => 'say something harmful'])->assertStatus(422);

        expect($response->json('code'))->toBe('validation_failed')->and(array_keys($response->json('errors')))->toBe([$key]);
        Http::assertNothingSent();
    })->with(['text', 'transcript', 'phrase', 'anything']);
});

describe('a voice that cannot be previewed', function () {
    it('answers 422 for a Tavus voice with no external engine, and calls no provider', function (array $engine) {
        previewKey('tavus', 'k');
        Http::fake();

        $response = previewPost(['provider' => 'tavus', 'voice_id' => 'stock-1', 'language' => 'it', ...$engine])->assertStatus(422);

        expect($response->json('code'))->toBe('validation_failed')->and(array_keys($response->json('errors')))->toBe(['tts_engine']);
        Http::assertNothingSent();
    })->with(['null' => [['tts_engine' => null]], 'omitted' => [[]]]);

    it('answers 422 for a provider that has no voices to preview', function () {
        Http::fake();

        previewPost(['provider' => 'mock', 'voice_id' => 'v1', 'language' => 'it'])->assertStatus(422);
        Http::assertNothingSent();
    });
});

describe('validation', function () {
    it('rejects a body that is not valid and reaches no provider', function (array $body, string $field) {
        previewKey('cartesia', 'k');
        Http::fake();

        $response = previewPost($body)->assertStatus(422);

        expect(array_keys($response->json('errors')))->toContain($field);
        Http::assertNothingSent();
    })->with([
        'unknown provider' => [['provider' => 'openai', 'voice_id' => 'v1', 'language' => 'it'], 'provider'],
        'no provider' => [['voice_id' => 'v1', 'language' => 'it'], 'provider'],
        'no voice id' => [['provider' => 'cartesia', 'language' => 'it'], 'voice_id'],
        'path traversal' => [['provider' => 'elevenlabs', 'voice_id' => '../../v1/user', 'language' => 'it'], 'voice_id'],
        'slash' => [['provider' => 'heygen', 'voice_id' => 'a/b', 'language' => 'it'], 'voice_id'],
        'query string' => [['provider' => 'heygen', 'voice_id' => 'a?b=1', 'language' => 'it'], 'voice_id'],
        'too long' => [['provider' => 'cartesia', 'voice_id' => str_repeat('a', 129), 'language' => 'it'], 'voice_id'],
        'no language' => [['provider' => 'cartesia', 'voice_id' => 'v1'], 'language'],
        'a language with no sentence' => [['provider' => 'cartesia', 'voice_id' => 'v1', 'language' => 'fr'], 'language'],
        'unknown engine' => [['provider' => 'tavus', 'voice_id' => 'v1', 'tts_engine' => 'other', 'language' => 'it'], 'tts_engine'],
        'the stock engine' => [['provider' => 'tavus', 'voice_id' => 'v1', 'tts_engine' => 'provider', 'language' => 'it'], 'tts_engine'],
    ]);

    it('anchors the voice id rule to the whole value: a trailing newline is not part of an id', function (string $id, bool $valid) {
        $rules = (new VoicePreviewRequest)->rules();

        expect(Validator::make(['voice_id' => $id], ['voice_id' => $rules['voice_id']])->passes())->toBe($valid);
    })->with([['abc', true], ["abc\n", false], ["ab\nc", false], ['a b', false]]);

    it('accepts a voice id of 128 characters', function () {
        previewKey('cartesia', 'k');
        Http::fake(['*' => fn () => Http::response(ENDPOINT_MP3)]);

        previewPost(['provider' => 'cartesia', 'voice_id' => str_repeat('a', 128), 'language' => 'it'])->assertOk();
    });
});

describe('credentials and failures', function () {
    it('answers 422 provider_credential_missing when the vendor has no key, and calls nothing', function () {
        Http::fake();

        $response = previewPost(['provider' => 'cartesia', 'voice_id' => 'v1', 'language' => 'it'])->assertStatus(422);

        expect($response->json('code'))->toBe('provider_credential_missing');
        Http::assertNothingSent();
    });

    it('answers 502 provider_error without the key or the provider body', function (int $status) {
        previewKey('cartesia', 'TEST_CARTESIA_KEY');
        Http::fake(['*' => Http::response(['error' => 'SECRET-PROVIDER-DETAIL TEST_CARTESIA_KEY'], $status)]);
        $logs = captureLogs();

        $response = previewPost(['provider' => 'cartesia', 'voice_id' => 'v1', 'language' => 'it'])->assertStatus(502);

        expect($response->json('code'))->toBe('provider_error')
            ->and($response->getContent())->not->toContain('SECRET-PROVIDER-DETAIL')->not->toContain('TEST_CARTESIA_KEY')
            ->and(implode("\n", $logs->getArrayCopy()))->not->toContain('SECRET-PROVIDER-DETAIL')->not->toContain('TEST_CARTESIA_KEY');
    })->with([401, 429, 500, 503]);

    it('answers 404 when the vendor does not know the voice', function () {
        previewKey('cartesia', 'k');
        Http::fake(['*' => Http::response(['error' => 'no such voice'], 404)]);

        $response = previewPost(['provider' => 'cartesia', 'voice_id' => 'v1', 'language' => 'it'])->assertNotFound();

        expect($response->json('code'))->toBe('not_found');
    });

    it('answers 502 when the vendor cannot be reached, and when the audio is unusable', function () {
        previewKey('cartesia', 'k');
        Http::fake(fn () => throw new ConnectionException('cURL error 28'));
        previewPost(['provider' => 'cartesia', 'voice_id' => 'v1', 'language' => 'it'])->assertStatus(502);

        Http::fake(['*' => Http::response('')]);
        previewPost(['provider' => 'cartesia', 'voice_id' => 'v2', 'language' => 'it'])->assertStatus(502);
    });

    it('needs the avatar_configs:read scope and a key', function () {
        Http::fake();

        previewPost(['provider' => 'cartesia', 'voice_id' => 'v1', 'language' => 'it'], $this->noScope)->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
        $this->postJson('/v1/voice-preview', [])->assertStatus(401);
        Http::assertNothingSent();
    });
});

describe('the cache across accounts', function () {
    it('does not serve one account\'s audio to another account with its own key', function () {
        $other = Account::factory()->create();
        [, $otherRaw] = issueKey($other, ['scopes' => ['avatar_configs:read']]);
        previewKey('cartesia', 'key-of-account-a-111');
        inAccount($other, fn () => ProviderCredential::create(['provider' => 'cartesia', 'api_key' => 'key-of-account-b-222']));
        Http::fake(['*' => fn () => Http::response(ENDPOINT_MP3)]);
        $body = ['provider' => 'cartesia', 'voice_id' => 'private-voice', 'language' => 'it'];

        previewPost($body)->assertOk();
        previewPost($body, $otherRaw)->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->header('X-API-Key') === ['key-of-account-b-222']);
    });
});
