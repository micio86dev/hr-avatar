<?php

use App\Models\Account;
use App\Models\ProviderWebhookReceipt;
use Illuminate\Support\Facades\Queue;

const WEBHOOK_BODY_LIMIT = 256 * 1024;

beforeEach(function () {
    Queue::fake();
    $this->token = bin2hex(random_bytes(32));
    $this->conversation = callbackConversation(Account::factory()->create(), $this->token);
});

/** A callback body of exactly `$bytes` bytes. */
function callbackOfSize(int $bytes): string
{
    $body = json_encode(inboundCallback(['pad' => '']), JSON_THROW_ON_ERROR);

    return json_encode(inboundCallback(['pad' => str_repeat('a', $bytes - strlen($body))]), JSON_THROW_ON_ERROR);
}

it('accepts a body of exactly 256 KB', function () {
    $body = callbackOfSize(WEBHOOK_BODY_LIMIT);
    expect(strlen($body))->toBe(WEBHOOK_BODY_LIMIT);

    postInbound('tavus', $this->token, $body)->assertOk();
});

it('refuses a body one byte over 256 KB with 413 payload_too_large, storing nothing', function () {
    $response = postInbound('tavus', $this->token, callbackOfSize(WEBHOOK_BODY_LIMIT + 1));

    $response->assertStatus(413)->assertJsonPath('code', 'payload_too_large');
    expect($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and(ProviderWebhookReceipt::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses on the declared Content-Length alone, before the body is read', function () {
    $response = postInbound('tavus', $this->token, inboundCallback(), ['CONTENT_LENGTH' => (string) (WEBHOOK_BODY_LIMIT + 1)]);

    $response->assertStatus(413)->assertJsonPath('code', 'payload_too_large');
});

it('refuses on the actual body when the declared length understates it', function () {
    $response = postInbound('tavus', $this->token, callbackOfSize(WEBHOOK_BODY_LIMIT + 10), ['CONTENT_LENGTH' => '100']);

    $response->assertStatus(413);
});

it('refuses an oversize body before judging the token', function () {
    postInbound('tavus', bin2hex(random_bytes(32)), callbackOfSize(300 * 1024))->assertStatus(413);
});
