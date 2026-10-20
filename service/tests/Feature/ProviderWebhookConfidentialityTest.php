<?php

use App\Conversations\ProviderEventIngestor;
use App\Models\Account;
use App\Models\ProviderWebhookReceipt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Queue::fake();
    RateLimiter::clear('provider-webhook');
    $this->account = Account::factory()->create();
    $this->token = bin2hex(random_bytes(32));
    $this->conversation = callbackConversation($this->account, $this->token);
});

/** Everything the application logged, as one string. */
function loggedText(ArrayObject $logs): string
{
    return implode("\n", $logs->getArrayCopy());
}

describe('the token in the logs', function () {
    it('never appears in the log output of a callback that fails, whichever way it fails', function (string $case) {
        $logs = captureLogs();
        $token = $this->token;
        $other = bin2hex(random_bytes(32));

        match ($case) {
            'unknown token' => postInbound('tavus', $other, inboundCallback()),
            'wrong provider' => postInbound('heygen', $token, inboundCallback()),
            'mismatch' => postInbound('tavus', $token, inboundCallback(['conversation_id' => 'x'])),
            'malformed' => postInbound('tavus', $token, 'not json'),
            'too large' => postInbound('tavus', $token, json_encode(inboundCallback(['pad' => str_repeat('a', 300 * 1024)]))),
            'internal error' => (function () use ($token) {
                app()->bind(ProviderEventIngestor::class, fn () => throw new RuntimeException('boom'));

                return postInbound('tavus', $token, inboundCallback())->assertStatus(500);
            })(),
        };

        $text = loggedText($logs);
        expect($text)->not->toContain($token)->and($text)->not->toContain($other)->and($text)->not->toContain('/webhooks/providers/');
    })->with(['unknown token', 'wrong provider', 'mismatch', 'malformed', 'too large', 'internal error']);

    it('keeps the route name, not the path, in the log context', function () {
        postInbound('tavus', $this->token, inboundCallback())->assertOk();

        expect(Log::sharedContext())->toHaveKey('route', 'webhooks.providers.receive')
            ->and(json_encode(Log::sharedContext()))->not->toContain($this->token);
    });

    it('is not part of the exception a server error reports', function () {
        $logs = captureLogs();
        app()->bind(ProviderEventIngestor::class, fn () => throw new RuntimeException('boom'));

        postInbound('tavus', $this->token, inboundCallback())->assertStatus(500);
        // The frames of the test that made the request show its own arguments; the application's frames must not.
        $application = collect(explode("\n", loggedText($logs)))->reject(fn (string $line) => str_contains($line, '/tests/'))->implode("\n");
        expect(loggedText($logs))->toContain('boom')->and($application)->not->toContain(substr($this->token, 0, 12));
    });

    it('is not echoed in any answer', function () {
        foreach ([postInbound('tavus', $this->token, inboundCallback()), postInbound('tavus', $this->token, 'not json'), postInbound('heygen', $this->token, [])] as $response) {
            expect($response->getContent())->not->toContain($this->token);
        }
    });
});

describe('the receipt payload', function () {
    it('is returned by no endpoint of the API', function () {
        [, $secret] = issueKey($this->account);
        $marker = 'RECEIPT-ONLY-MARKER-'.bin2hex(random_bytes(4));
        postInbound('tavus', $this->token, inboundCallback(['properties' => ['shutdown_reason' => 'participant_left', 'note' => $marker]]))->assertOk();
        expect(ProviderWebhookReceipt::withoutGlobalScopes()->count())->toBe(1);
        $headers = ['Authorization' => "Bearer {$secret}"];

        $bodies = [
            $this->getJson("/v1/conversations/{$this->conversation->id}", $headers)->getContent(),
            $this->getJson("/v1/conversations/{$this->conversation->id}/transcript", $headers)->getContent(),
            postInbound('tavus', $this->token, inboundCallback())->getContent(),
        ];

        foreach ($bodies as $body) {
            expect($body)->not->toContain($marker);
        }
        expect((new ProviderWebhookReceipt)->getHidden())->toContain('payload');
    });
});
