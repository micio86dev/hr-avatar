<?php

use App\Models\Account;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->account = Account::factory()->create();
    Route::middleware(['auth.secret', 'throttle:secret'])->get('/v1/_probe', fn () => ['ok' => true]);
});

function asKey(string $raw): array
{
    return ['Authorization' => "Bearer {$raw}"];
}

it('defaults to 600 requests per minute per secret key', function () {
    expect(config('hr-avatar.rate_limits.secret'))->toBe(600);
});

it('answers 429 with Retry-After once the key is over its limit', function () {
    config()->set('hr-avatar.rate_limits.secret', 3);
    [, $raw] = issueKey($this->account);

    foreach (range(1, 3) as $request) {
        $this->getJson('/v1/_probe', asKey($raw))->assertOk();
    }
    $response = $this->getJson('/v1/_probe', asKey($raw));

    $response->assertStatus(429);
    expect($response->json('code'))->toBe('rate_limited')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('3')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('0');
});

it('reports the remaining budget on every successful response', function () {
    config()->set('hr-avatar.rate_limits.secret', 3);
    [, $raw] = issueKey($this->account);

    $remaining = collect(range(1, 3))->map(function () use ($raw) {
        $response = $this->getJson('/v1/_probe', asKey($raw))->assertOk();
        expect($response->headers->get('X-RateLimit-Limit'))->toBe('3');

        return $response->headers->get('X-RateLimit-Remaining');
    })->all();

    expect($remaining)->toBe(['2', '1', '0']);
});

it('gives each key its own budget', function () {
    config()->set('hr-avatar.rate_limits.secret', 2);
    [, $first] = issueKey($this->account);
    [, $second] = issueKey($this->account);

    $this->getJson('/v1/_probe', asKey($first))->assertOk();
    $this->getJson('/v1/_probe', asKey($first))->assertOk();
    $this->getJson('/v1/_probe', asKey($first))->assertStatus(429);

    $this->getJson('/v1/_probe', asKey($second))->assertOk();
});

it('reads the limit when the request is made, not when the application booted', function () {
    [, $raw] = issueKey($this->account);
    config()->set('hr-avatar.rate_limits.secret', 1);

    $this->getJson('/v1/_probe', asKey($raw))->assertOk();
    $this->getJson('/v1/_probe', asKey($raw))->assertStatus(429);
});
