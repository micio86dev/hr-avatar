<?php

use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Http\Middleware\LimitWebhookBody;
use App\Models\Account;
use App\Models\ProviderWebhookReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OneShotStream;

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
    $streamOf = function (int $bytes) {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, str_repeat('a', $bytes));
        rewind($stream);

        return $stream;
    };

    it('is refused with 413 after reading no more than one byte past the limit', function () use ($streamOf) {
        $stream = $streamOf(LimitWebhookBody::MAX_BYTES * 4);
        $request = Request::create('/v1/webhooks/providers/tavus/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $stream);

        try {
            (new LimitWebhookBody)->handle($request, fn () => response('passed'));
            $this->fail('A body past the limit was let through.');
        } catch (ApiProblem $problem) {
            expect($problem->errorCode)->toBe(ErrorCode::PayloadTooLarge)->and(ftell($stream))->toBe(LimitWebhookBody::MAX_BYTES + 1);
        }
    });

    it('lets a body of exactly the limit through, readable by what follows', function () use ($streamOf) {
        $request = Request::create('/v1/webhooks/providers/tavus/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $streamOf(LimitWebhookBody::MAX_BYTES));

        $response = (new LimitWebhookBody)->handle($request, fn (Request $request) => response((string) strlen($request->getContent())));

        expect($response->getContent())->toBe((string) LimitWebhookBody::MAX_BYTES);
    });
});

describe('through the real HTTP kernel with a body that can be read only once', function () {
    // Not sent as JSON: the framework decodes a JSON body itself before any middleware runs, so the body would be
    // read (and, here, gone) before `LimitWebhookBody`. Providers are not obliged to say `application/json`.
    it('hands the body it read on to the controller, which stores it whole', function (bool $declareLength, int $chunk) {
        $sent = inboundCallback(['properties' => ['shutdown_reason' => 'participant_left', 'marker' => str_repeat('read-once ', 200)]]);
        $body = json_encode($sent, JSON_THROW_ON_ERROR);
        $server = ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json', ...($declareLength ? ['CONTENT_LENGTH' => (string) strlen($body)] : [])];

        $response = app()->handle(Request::create("/v1/webhooks/providers/tavus/{$this->token}", 'POST', [], [], [], $server, OneShotStream::open($body, $chunk)));

        expect($response->getStatusCode())->toBe(200)
            ->and(ProviderWebhookReceipt::withoutGlobalScopes()->firstOrFail()->payload)->toEqual($sent);
    })->with([
        'a declared Content-Length, read in one piece' => [true, 1 << 20],
        'no Content-Length, read in one piece' => [false, 1 << 20],
        'no Content-Length, read in 7-byte chunks (chunked)' => [false, 7],
    ]);
});

// Review rv28: the body is handed back by rebuilding the request, so what a middleware before this one set on the request
// has to come out the other side. The seven input bags and the headers are copied; the rest is the request's own state.
describe('what was set on the request before the middleware', function () {
    it('is still there afterwards: the attributes, the headers, the request format, the method and the resolvers', function () {
        $request = Request::create('/v1/webhooks/providers/tavus/x', 'POST', ['q' => '1'], ['c' => '2'], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUEST_ID' => 'req-1'], '{"a":1}');
        $request->attributes->set('request_id', 'req-1');
        $request->headers->set('X-Added', 'later');
        $request->setRequestFormat('xml');
        $request->setMethod('PUT');
        $request->setUserResolver(fn () => 'the-user');
        $request->setRouteResolver(fn () => 'the-route');

        $seen = null;
        (new LimitWebhookBody)->handle($request, function (Request $request) use (&$seen) {
            $seen = $request;

            return response('');
        });

        expect($seen)->toBe($request)
            ->and($request->attributes->get('request_id'))->toBe('req-1')
            ->and($request->headers->get('X-Added'))->toBe('later')
            ->and($request->headers->get('X-Request-Id'))->toBe('req-1')
            ->and($request->getRequestFormat())->toBe('xml')
            ->and($request->getMethod())->toBe('PUT')
            ->and($request->user())->toBe('the-user')
            ->and($request->route())->toBe('the-route')
            ->and($request->request->get('q'))->toBe('1')
            ->and($request->cookies->get('c'))->toBe('2')
            ->and($request->getContent())->toBe('{"a":1}');
    });
});
