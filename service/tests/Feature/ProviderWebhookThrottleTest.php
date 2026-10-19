<?php

use App\Models\Account;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Queue::fake();
    RateLimiter::clear('provider-webhook');
    $this->token = bin2hex(random_bytes(32));
    callbackConversation(Account::factory()->create(), $this->token);
});

it('allows 120 requests a minute from one address and answers the next with 429 rate_limited', function () {
    foreach (range(1, 120) as $ignored) {
        postInbound('tavus', bin2hex(random_bytes(32)), inboundCallback(), ['REMOTE_ADDR' => '203.0.113.7'])->assertNotFound();
    }

    $response = postInbound('tavus', $this->token, inboundCallback(), ['REMOTE_ADDR' => '203.0.113.7']);

    $response->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
    expect($response->headers->get('Content-Type'))->toBe('application/problem+json');
});

it('keeps a budget per address', function () {
    foreach (range(1, 121) as $ignored) {
        postInbound('tavus', bin2hex(random_bytes(32)), inboundCallback(), ['REMOTE_ADDR' => '203.0.113.7']);
    }

    postInbound('tavus', $this->token, inboundCallback(), ['REMOTE_ADDR' => '203.0.113.8'])->assertOk();
});

it('counts a valid callback too, and sends the rate headers', function () {
    $response = postInbound('tavus', $this->token, inboundCallback(), ['REMOTE_ADDR' => '203.0.113.9']);

    $response->assertOk()->assertHeader('X-RateLimit-Limit', '120')->assertHeader('X-RateLimit-Remaining', '119');
});

it('does not draw on the budget of any key', function () {
    [, $secret] = issueKey(Account::factory()->create());
    foreach (range(1, 5) as $ignored) {
        postInbound('tavus', $this->token, inboundCallback(['timestamp' => "2026-10-19T12:10:0{$ignored}Z"]), ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_AUTHORIZATION' => "Bearer {$secret}"])->assertOk();
    }

    $this->getJson('/v1/api-keys', ['Authorization' => "Bearer {$secret}"])->assertOk()->assertHeader('X-RateLimit-Remaining', '599');
});
