<?php

use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Http\Middleware\LimitWebhookBody;
use App\Models\Account;
use App\Models\ProviderWebhookReceipt;
use Illuminate\Http\Request;
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

describe('a body with no Content-Length (chunked)', function () {
    function streamOf(int $bytes)
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, str_repeat('a', $bytes));
        rewind($stream);

        return $stream;
    }

    it('is refused with 413 after reading no more than one byte past the limit', function () {
        $stream = streamOf(LimitWebhookBody::MAX_BYTES * 4);
        $request = Request::create('/v1/webhooks/providers/tavus/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $stream);

        try {
            (new LimitWebhookBody)->handle($request, fn () => response('passed'));
            $this->fail('A body past the limit was let through.');
        } catch (ApiProblem $problem) {
            expect($problem->errorCode)->toBe(ErrorCode::PayloadTooLarge)->and(ftell($stream))->toBe(LimitWebhookBody::MAX_BYTES + 1);
        }
    });

    it('lets a body of exactly the limit through, readable by what follows', function () {
        $request = Request::create('/v1/webhooks/providers/tavus/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], streamOf(LimitWebhookBody::MAX_BYTES));

        $response = (new LimitWebhookBody)->handle($request, fn (Request $request) => response((string) strlen($request->getContent())));

        expect($response->getContent())->toBe((string) LimitWebhookBody::MAX_BYTES);
    });
});

describe('through the real HTTP kernel, where the body is read from the SAPI input', function () {
    /** A request the way the SAPI builds it: no content is handed over, it is read from `php://input` on demand. */
    function kernelRequest(string $token, array $server): Request
    {
        return Request::create("/v1/webhooks/providers/tavus/{$token}", 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...$server], null);
    }

    it('leaves the body readable for the form request when a Content-Length is declared', function () {
        $response = app()->handle(kernelRequest($this->token, ['CONTENT_LENGTH' => '0']));

        // An empty body is not a callback: 422, and never the 500 of a body that cannot be read twice.
        expect($response->getStatusCode())->toBe(422)->and(json_decode($response->getContent(), true)['code'])->toBe('validation_failed');
    });

    it('leaves the body readable for the form request when there is no Content-Length', function () {
        $response = app()->handle(kernelRequest($this->token, []));

        expect($response->getStatusCode())->toBe(422)->and(json_decode($response->getContent(), true)['code'])->toBe('validation_failed');
    });

    it('hands a streamed body within the limit on to the controller whole', function () {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, json_encode(inboundCallback(), JSON_THROW_ON_ERROR));
        rewind($stream);
        $request = Request::create("/v1/webhooks/providers/tavus/{$this->token}", 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $stream);

        expect(app()->handle($request)->getStatusCode())->toBe(200);
    });
});
