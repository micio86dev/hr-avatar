<?php

use App\Models\Account;
use App\Models\ProviderCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const THROTTLE_MP3 = "ID3\x03\x00\x00\x00\x00\x00\x00FAKE-MP3-BYTES";

beforeEach(function () {
    Cache::flush();
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['avatar_configs:read']]);
    inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'cartesia', 'api_key' => 'TEST_CARTESIA_KEY']));
    // A fresh voice id per call, so a cached sample never hides a throttled call.
    Http::fake(['api.cartesia.ai/tts/bytes' => fn () => Http::response(THROTTLE_MP3)]);
});

function throttledPreview(string $raw, ?string $voice = null): TestResponse
{
    return test()->postJson('/v1/voice-preview', ['provider' => 'cartesia', 'voice_id' => $voice ?? 'v-'.bin2hex(random_bytes(4)), 'tts_engine' => null, 'language' => 'en'], ['Authorization' => 'Bearer '.$raw]);
}

it('defaults to 10 previews per minute per key', function () {
    expect(config('hr-avatar.rate_limits.preview'))->toBe(10);
});

it('answers 429 with the problem body and the rate-limit headers once the key is over its preview budget', function () {
    config()->set('hr-avatar.rate_limits.preview', 2);

    throttledPreview($this->raw)->assertOk();
    throttledPreview($this->raw)->assertOk();
    $response = throttledPreview($this->raw)->assertStatus(429);

    expect($response->json('code'))->toBe('rate_limited')
        ->and($response->json('status'))->toBe(429)
        ->and($response->json('detail'))->toBeString()
        ->and($response->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('2')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('0')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
    Http::assertSentCount(2);
});

it('reports the preview budget on every successful preview', function () {
    config()->set('hr-avatar.rate_limits.preview', 3);

    $remaining = collect(range(1, 3))->map(function () {
        $response = throttledPreview($this->raw)->assertOk();
        expect($response->headers->get('X-RateLimit-Limit'))->toBe('3');

        return $response->headers->get('X-RateLimit-Remaining');
    })->all();

    expect($remaining)->toBe(['2', '1', '0']);
});

it('gives each key its own preview budget', function () {
    config()->set('hr-avatar.rate_limits.preview', 1);
    [, $other] = issueKey($this->account, ['scopes' => ['avatar_configs:read']]);

    throttledPreview($this->raw)->assertOk();
    throttledPreview($this->raw)->assertStatus(429);

    throttledPreview($other)->assertOk();
});

it('counts a refused preview too: the budget guards the endpoint, not only the vendor call', function () {
    config()->set('hr-avatar.rate_limits.preview', 1);

    $this->postJson('/v1/voice-preview', ['provider' => 'cartesia'], ['Authorization' => 'Bearer '.$this->raw])->assertStatus(422);
    throttledPreview($this->raw)->assertStatus(429);
    Http::assertNothingSent();
});

it('keeps the preview budget apart from the key budget of every other route', function () {
    config()->set('hr-avatar.rate_limits.preview', 1);

    throttledPreview($this->raw)->assertOk();
    throttledPreview($this->raw)->assertStatus(429);

    $this->getJson('/v1/avatar-configs', ['Authorization' => 'Bearer '.$this->raw])->assertOk();
});

it('is not spent by other routes', function () {
    config()->set('hr-avatar.rate_limits.preview', 1);

    $this->getJson('/v1/avatar-configs', ['Authorization' => 'Bearer '.$this->raw])->assertOk();
    $this->getJson('/v1/catalogue', ['Authorization' => 'Bearer '.$this->raw])->assertOk();

    throttledPreview($this->raw)->assertOk();
});
