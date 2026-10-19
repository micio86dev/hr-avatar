<?php

use App\Accounts\CurrentAccount;
use App\Jobs\DeliverWebhook;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Outbound\DeliveryStatus;
use App\Webhooks\Outbound\EventType;
use App\Webhooks\Outbound\OutboundHostGuard;
use App\Webhooks\Outbound\WebhookDispatcher;
use App\Webhooks\Outbound\WebhookSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-10-19 12:00:00');
    $this->account = Account::factory()->create();
});

/**
 * An endpoint and a pending delivery of `conversation.ended` for it, made the way the service makes them.
 *
 * @param  array<string, mixed>  $endpoint
 */
function pendingDelivery(array $endpoint = []): WebhookDelivery
{
    $config = inAccount(test()->account, fn () => AvatarConfig::factory()->create());
    $conversation = inAccount(test()->account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id]));
    test()->endpoint = inAccount(test()->account, fn () => WebhookEndpoint::factory()->create(['url' => 'https://hooks.example.com/hra']));

    $ids = app(WebhookDispatcher::class)->record($conversation, EventType::ConversationEnded);
    // The endpoint changes after the event was recorded, as it can between recording and delivering.
    inAccount(test()->account, fn () => test()->endpoint->forceFill($endpoint)->save());
    Queue::fake();

    return WebhookDelivery::withoutGlobalScopes()->findOrFail($ids[0]);
}

/**
 * Runs the delivery job once, as a worker would after the previous attempt's delay.
 */
function runDelivery(WebhookDelivery|string $delivery): void
{
    app()->call([new DeliverWebhook(test()->account->id, $delivery instanceof WebhookDelivery ? $delivery->id : $delivery), 'handle']);
}

function stored(WebhookDelivery $delivery): WebhookDelivery
{
    return WebhookDelivery::withoutGlobalScopes()->findOrFail($delivery->id);
}

describe('a request', function () {
    it('is a POST of the stored bytes to the endpoint url, signed with this attempt\'s timestamp', function () {
        $delivery = pendingDelivery();
        Http::fake(['*' => Http::response('', 204)]);

        runDelivery($delivery);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($delivery) {
            $timestamp = (int) $request->header('X-HRA-Timestamp')[0];

            return $request->method() === 'POST'
                && $request->url() === 'https://hooks.example.com/hra'
                && $request->body() === $delivery->payload
                && $request->header('Content-Type') === ['application/json']
                && $timestamp === now()->getTimestamp()
                && $request->header('X-HRA-Signature') === [(new WebhookSigner)->sign($this->endpoint->secret, $timestamp, $delivery->payload)]
                && $request->header('X-HRA-Event-Id') === [$delivery->event_id]
                && $request->header('X-HRA-Event-Type') === ['conversation.ended'];
        });
    });

    it('can be verified by a receiver that knows the secret and the window', function () {
        $delivery = pendingDelivery();
        $seen = null;
        Http::fake(function (Request $request) use (&$seen) {
            $seen = $request;

            return Http::response('', 200);
        });

        runDelivery($delivery);

        expect((new WebhookSigner)->verify($this->endpoint->secret, $seen->header('X-HRA-Signature')[0], (int) $seen->header('X-HRA-Timestamp')[0], $seen->body(), now()->getTimestamp() + 20))->toBeTrue();
    });

    it('times out after 10 seconds and never follows a redirect', function () {
        $delivery = pendingDelivery();
        $options = null;
        Http::fake(function (Request $request, array $given) use (&$options) {
            $options = $given;

            return Http::response('', 204);
        });

        runDelivery($delivery);

        expect($options['timeout'])->toBe(10)->and($options['allow_redirects'])->toBeFalse();
    });

    it('re-signs every attempt: a new timestamp and signature, the same event id and body', function () {
        $delivery = pendingDelivery();
        $seen = [];
        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = $request;

            return Http::response('', 500);
        });

        runDelivery($delivery);
        Carbon::setTestNow(now()->addSeconds(10));
        runDelivery($delivery);

        expect($seen)->toHaveCount(2)
            ->and($seen[1]->header('X-HRA-Timestamp'))->not->toBe($seen[0]->header('X-HRA-Timestamp'))
            ->and($seen[1]->header('X-HRA-Signature'))->not->toBe($seen[0]->header('X-HRA-Signature'))
            ->and($seen[1]->header('X-HRA-Event-Id'))->toBe($seen[0]->header('X-HRA-Event-Id'))
            ->and($seen[1]->body())->toBe($seen[0]->body());
    });
});

describe('the outcome of an attempt', function () {
    it('succeeds on any 2xx, once', function (int $status) {
        $delivery = pendingDelivery();
        Http::fake(['*' => Http::response('ok', $status)]);

        runDelivery($delivery);

        $row = stored($delivery);
        expect($row->status)->toBe(DeliveryStatus::Succeeded)->and($row->attempts)->toBe(1)->and($row->last_response_status)->toBe($status)
            ->and($row->last_error)->toBeNull()->and($row->next_attempt_at)->toBeNull()
            ->and($row->last_attempt_at->equalTo(now()))->toBeTrue()->and($row->completed_at->equalTo(now()))->toBeTrue();
        Queue::assertNothingPushed();
    })->with([200, 201, 204, 299]);

    it('fails the attempt on anything else and retries, recording a safe code', function (int $status) {
        $delivery = pendingDelivery();
        Http::fake(['*' => Http::response(['detail' => 'RESPONSE-BODY-SECRET'], $status)]);

        runDelivery($delivery);

        $row = stored($delivery);
        expect($row->status)->toBe(DeliveryStatus::Pending)->and($row->attempts)->toBe(1)->and($row->last_response_status)->toBe($status)
            ->and($row->last_error)->toBe("http_{$status}")->and($row->completed_at)->toBeNull()
            ->and($row->next_attempt_at->equalTo(now()->addSeconds(10)))->toBeTrue();
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->deliveryId === $delivery->id && $job->delay === 10);
    })->with([199, 300, 301, 302, 400, 401, 404, 410, 429, 500, 503]);

    it('retries after 10 s, 60 s, 300 s, 1800 s and 7200 s and gives up after the sixth failure', function () {
        $delivery = pendingDelivery();
        Http::fake(['*' => Http::response('', 500)]);

        foreach ([10, 60, 300, 1800, 7200] as $attempt => $delay) {
            Queue::fake();
            runDelivery($delivery);

            $row = stored($delivery);
            expect($row->status)->toBe(DeliveryStatus::Pending)->and($row->attempts)->toBe($attempt + 1)
                ->and($row->next_attempt_at->equalTo(now()->addSeconds($delay)))->toBeTrue();
            Queue::assertPushed(DeliverWebhook::class, 1);
            Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->delay === $delay);
            Carbon::setTestNow(now()->addSeconds($delay));
        }

        Queue::fake();
        runDelivery($delivery);

        $row = stored($delivery);
        expect($row->status)->toBe(DeliveryStatus::Failed)->and($row->attempts)->toBe(6)->and($row->last_error)->toBe('http_500')
            ->and($row->next_attempt_at)->toBeNull()->and($row->completed_at->equalTo(now()))->toBeTrue();
        Queue::assertNothingPushed();
        Http::assertSentCount(6);
    });

    it('recovers mid-way: two failures and then a 200 is a success at attempt 3', function () {
        $delivery = pendingDelivery();
        Http::fake(['*' => Http::sequence()->push('', 500)->push('', 503)->push('', 200)]);

        foreach (range(1, 3) as $ignored) {
            runDelivery($delivery);
        }

        $row = stored($delivery);
        expect($row->status)->toBe(DeliveryStatus::Succeeded)->and($row->attempts)->toBe(3)->and($row->last_error)->toBeNull()->and($row->next_attempt_at)->toBeNull();
    });

    it('records a timeout as such, with no response status', function () {
        $delivery = pendingDelivery();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received'));

        runDelivery($delivery);

        $row = stored($delivery);
        expect($row->last_error)->toBe('timeout')->and($row->last_response_status)->toBeNull()->and($row->status)->toBe(DeliveryStatus::Pending)->and($row->attempts)->toBe(1);
    });

    it('records any other connection failure as connection_failed, whatever the exception says', function () {
        $delivery = pendingDelivery();
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: hooks.example.com'));

        runDelivery($delivery);

        $row = stored($delivery);
        expect($row->last_error)->toBe('connection_failed')->and($row->last_response_status)->toBeNull();
    });

    it('clears the last error and the retry time on success', function () {
        $delivery = pendingDelivery();
        Http::fake(['*' => Http::sequence()->push('', 500)->push('', 200)]);

        runDelivery($delivery);
        runDelivery($delivery);

        $row = stored($delivery);
        expect($row->last_error)->toBeNull()->and($row->next_attempt_at)->toBeNull()->and($row->last_response_status)->toBe(200);
    });
});

describe('a delivery that should not be attempted', function () {
    it('does nothing for a delivery that is already finished', function (DeliveryStatus $status) {
        $delivery = pendingDelivery();
        WebhookDelivery::withoutGlobalScopes()->whereKey($delivery->id)->update(['status' => $status->value, 'attempts' => 2]);
        Http::fake();

        runDelivery($delivery);

        Http::assertNothingSent();
        expect(stored($delivery)->attempts)->toBe(2);
        Queue::assertNothingPushed();
    })->with([DeliveryStatus::Succeeded, DeliveryStatus::Failed]);

    it('does nothing for a delivery that no longer exists', function () {
        Http::fake();

        runDelivery('01j0000000000000000000000x');

        Http::assertNothingSent();
    });

    it('stops without a request when the endpoint was disabled during the retries, and fails the delivery', function () {
        $delivery = pendingDelivery();
        Http::fake(['*' => Http::response('', 500)]);
        runDelivery($delivery);
        inAccount($this->account, fn () => $this->endpoint->forceFill(['enabled' => false])->save());
        Carbon::setTestNow(now()->addSeconds(10));
        Queue::fake();

        runDelivery($delivery);

        $row = stored($delivery);
        expect($row->status)->toBe(DeliveryStatus::Failed)->and($row->attempts)->toBe(1)->and($row->last_error)->toBe('endpoint_disabled')
            ->and($row->next_attempt_at)->toBeNull()->and($row->completed_at->equalTo(now()))->toBeTrue();
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    });

    it('stops when the endpoint is disabled before the first attempt', function () {
        $delivery = pendingDelivery(['enabled' => false]);
        Http::fake();

        runDelivery($delivery);

        Http::assertNothingSent();
        expect(stored($delivery)->status)->toBe(DeliveryStatus::Failed);
    });

    it('does not move on when another run already recorded the attempt', function () {
        $delivery = pendingDelivery();
        Http::fake(function () use ($delivery) {
            // Another worker finished the same attempt while this request was in flight.
            WebhookDelivery::withoutGlobalScopes()->whereKey($delivery->id)->update(['attempts' => 1, 'last_error' => 'http_500']);

            return Http::response('', 500);
        });

        runDelivery($delivery);

        expect(stored($delivery)->attempts)->toBe(1);
        Queue::assertNothingPushed();
    });
});

describe('the account', function () {
    it('is the job\'s own: a job for another account finds no delivery of this one and does nothing', function () {
        $delivery = pendingDelivery();
        Http::fake();

        app()->call([new DeliverWebhook(Account::factory()->create()->id, $delivery->id), 'handle']);
        (new DeliverWebhook(Account::factory()->create()->id, $delivery->id))->failed(new RuntimeException('boom'));

        Http::assertNothingSent();
        expect(stored($delivery)->status)->toBe(DeliveryStatus::Pending)->and(stored($delivery)->attempts)->toBe(0);
        Queue::assertNothingPushed();
    });

    it('is carried by the retry: the job queued for the next attempt names the same account and delivery', function () {
        $delivery = pendingDelivery();
        Http::fake(['*' => Http::response('', 500)]);

        runDelivery($delivery);

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->accountId === $this->account->id && $job->deliveryId === $delivery->id);
    });
});

describe('the destination at delivery time', function () {
    it('sends nothing to an address that is not public, and counts the attempt as failed and retried', function (string $address) {
        $delivery = pendingDelivery();
        app()->instance(OutboundHostGuard::class, new OutboundHostGuard(true, fn () => [$address]));
        Http::fake();

        runDelivery($delivery);

        Http::assertNothingSent();
        $row = stored($delivery);
        expect($row->status)->toBe(DeliveryStatus::Pending)->and($row->attempts)->toBe(1)->and($row->last_error)->toBe('destination_not_allowed')->and($row->last_response_status)->toBeNull();
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->delay === 10);
    })->with(['the metadata address' => '169.254.169.254', 'a private address' => '10.0.0.5', 'loopback' => '127.0.0.1', 'IPv6 loopback' => '::1']);

    it('is also refused when any one of several resolved addresses is not public', function () {
        $delivery = pendingDelivery();
        app()->instance(OutboundHostGuard::class, new OutboundHostGuard(true, fn () => ['93.184.216.34', '10.0.0.5']));
        Http::fake();

        runDelivery($delivery);

        Http::assertNothingSent();
        expect(stored($delivery)->last_error)->toBe('destination_not_allowed');
    });

    it('pins the connection to the addresses that were checked', function () {
        $delivery = pendingDelivery(['url' => 'https://hooks.example.com:8443/hra']);
        app()->instance(OutboundHostGuard::class, new OutboundHostGuard(true, fn () => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']));
        $options = null;
        Http::fake(function (Request $request, array $given) use (&$options) {
            $options = $given;

            return Http::response('', 204);
        });

        runDelivery($delivery);

        expect($options['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.com:8443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]']);
    });

    it('pins to port 443 when the url names none', function () {
        $delivery = pendingDelivery();
        app()->instance(OutboundHostGuard::class, new OutboundHostGuard(true, fn () => ['93.184.216.34']));
        $options = null;
        Http::fake(function (Request $request, array $given) use (&$options) {
            $options = $given;

            return Http::response('', 204);
        });

        runDelivery($delivery);

        expect($options['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.com:443:93.184.216.34']);
    });

    it('pins nothing for a url that is an address already, for a host that does not resolve, or where destinations are not enforced', function (string $url, Closure $guard) {
        $delivery = pendingDelivery(['url' => $url]);
        app()->instance(OutboundHostGuard::class, $guard());
        $options = null;
        Http::fake(function (Request $request, array $given) use (&$options) {
            $options = $given;

            return Http::response('', 204);
        });

        runDelivery($delivery);

        expect($options)->not->toHaveKey('curl');
    })->with([
        'an IPv4 literal' => ['https://93.184.216.34/hra', fn () => new OutboundHostGuard(true, fn () => throw new LogicException)],
        'an IPv6 literal' => ['https://[2606:2800:220:1::1]/hra', fn () => new OutboundHostGuard(true, fn () => throw new LogicException)],
        'a host that does not resolve' => ['https://hooks.example.com/hra', fn () => new OutboundHostGuard(true, fn () => [])],
        'destinations not enforced' => ['http://localhost:9000/hra', fn () => new OutboundHostGuard(false)],
    ]);

    it('attempts a host that does not resolve and records the connection failure', function () {
        $delivery = pendingDelivery();
        app()->instance(OutboundHostGuard::class, new OutboundHostGuard(true, fn () => []));
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        runDelivery($delivery);

        expect(stored($delivery)->last_error)->toBe('connection_failed');
    });
});

describe('the job', function () {
    it('carries the account and delivery ids and nothing else, and does not retry through the queue', function () {
        $job = new DeliverWebhook('01j0000000000000000000000a', '01j0000000000000000000000x');

        expect($job->tries)->toBe(1)
            ->and((new ReflectionMethod(DeliverWebhook::class, '__construct'))->getParameters())->toHaveCount(2)
            ->and(array_keys(get_object_vars($job)))->toContain('accountId', 'deliveryId')
            ->and(serialize($job))->not->toContain('secret')->not->toContain('https://');
    });

    it('runs without any account bound and leaves none bound', function () {
        $delivery = pendingDelivery();
        Http::fake(['*' => Http::response('', 204)]);
        expect(app(CurrentAccount::class)->id())->toBeNull();

        runDelivery($delivery);

        expect(stored($delivery)->status)->toBe(DeliveryStatus::Succeeded)->and(app(CurrentAccount::class)->id())->toBeNull();
    });

    it('marks a delivery failed when the job itself dies, and never touches a finished one', function () {
        $pending = pendingDelivery();
        $done = pendingDelivery();
        WebhookDelivery::withoutGlobalScopes()->whereKey($done->id)->update(['status' => 'succeeded', 'completed_at' => now()->subHour()]);

        (new DeliverWebhook($this->account->id, $pending->id))->failed(new RuntimeException('boom'));
        (new DeliverWebhook($this->account->id, $done->id))->failed(new RuntimeException('boom'));
        (new DeliverWebhook($this->account->id, '01j0000000000000000000000x'))->failed(new RuntimeException('boom'));

        $row = stored($pending);
        expect($row->status)->toBe(DeliveryStatus::Failed)->and($row->last_error)->toBe('internal_error')->and($row->completed_at->equalTo(now()))->toBeTrue()->and($row->next_attempt_at)->toBeNull()
            ->and(stored($done)->status)->toBe(DeliveryStatus::Succeeded)->and(stored($done)->completed_at->equalTo(now()->subHour()))->toBeTrue();
    });
});
