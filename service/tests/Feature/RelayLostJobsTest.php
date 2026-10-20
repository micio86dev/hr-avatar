<?php

use App\Console\Commands\RelayLostJobs;
use App\Jobs\DeliverWebhook;
use App\Jobs\ProcessProviderEvent;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ProviderWebhookReceipt;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Outbound\DeliveryStatus;
use App\Webhooks\Outbound\EventType;
use App\Webhooks\Outbound\WebhookDispatcher;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-10-20 12:00:00');
    $this->account = Account::factory()->create();
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    $this->conversation = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => $this->config->id]));
    $this->endpoint = inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
});

/**
 * A delivery row as the dispatcher leaves it, `$ageSeconds` old.
 *
 * @param  array<string, mixed>  $attributes
 */
function relayDelivery(int $ageSeconds, array $attributes = [], ?Account $account = null, ?Conversation $conversation = null, ?WebhookEndpoint $endpoint = null): string
{
    $account ??= test()->account;
    // One delivery per endpoint, event and conversation: every row gets a conversation of its own.
    $conversation ??= inAccount($account, fn () => Conversation::factory()->create(['avatar_config_id' => test()->config->id]));
    $endpoint ??= test()->endpoint;
    $id = strtolower((string) Str::ulid());

    DB::table('webhook_deliveries')->insert([
        'id' => $id, 'account_id' => $account->id, 'webhook_endpoint_id' => $endpoint->id, 'conversation_id' => $conversation->id,
        'event_id' => 'evt_'.strtolower((string) Str::ulid()), 'event_type' => 'conversation.ended', 'payload' => '{}', 'status' => 'pending', 'attempts' => 0,
        'created_at' => now()->subSeconds($ageSeconds), 'updated_at' => now()->subSeconds($ageSeconds), ...$attributes,
    ]);

    return $id;
}

/**
 * A provider receipt row, `$ageSeconds` old.
 *
 * @param  array<string, mixed>  $attributes
 */
function relayReceipt(int $ageSeconds, array $attributes = [], ?Account $account = null, ?Conversation $conversation = null): string
{
    $account ??= test()->account;
    $conversation ??= test()->conversation;
    $id = strtolower((string) Str::ulid());

    DB::table('provider_webhook_receipts')->insert([
        'id' => $id, 'account_id' => $account->id, 'conversation_id' => $conversation->id, 'provider' => 'tavus', 'provider_ref' => 'c1',
        'idempotency_key' => 'k-'.$id, 'kind' => 'ended', 'occurred_at' => now()->subSeconds($ageSeconds), 'payload' => '{}',
        'created_at' => now()->subSeconds($ageSeconds), 'updated_at' => now()->subSeconds($ageSeconds), ...$attributes,
    ]);

    return $id;
}

function relay(): int
{
    return Artisan::call('hra:relay');
}

function relayedDeliveries(): array
{
    return Queue::pushed(DeliverWebhook::class)->map(fn (DeliverWebhook $job) => $job->deliveryId)->sort()->values()->all();
}

function relayedReceipts(): array
{
    return Queue::pushed(ProcessProviderEvent::class)->map(fn (ProcessProviderEvent $job) => $job->receiptId)->sort()->values()->all();
}

describe('webhook deliveries', function () {
    it('queues a pending delivery whose job was never pushed, for its own account', function () {
        $id = relayDelivery(600);

        expect(relay())->toBe(0);

        Queue::assertPushed(DeliverWebhook::class, 1);
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->deliveryId === $id && $job->accountId === $this->account->id);
    });

    it('queues a pending retry whose next attempt is long overdue: the retry was written and never queued', function () {
        $id = relayDelivery(7200, ['attempts' => 2, 'last_attempt_at' => now()->subSeconds(900), 'next_attempt_at' => now()->subSeconds(600)]);

        relay();

        expect(relayedDeliveries())->toBe([$id]);
    });

    it('leaves alone a delivery that is young, a retry that is not due or only just due, and one that is over', function () {
        relayDelivery(120);
        relayDelivery(7200, ['attempts' => 1, 'next_attempt_at' => now()->addMinutes(30)]);
        relayDelivery(7200, ['attempts' => 1, 'next_attempt_at' => now()->subSeconds(60)]);
        relayDelivery(7200, ['status' => 'succeeded', 'attempts' => 1, 'completed_at' => now()->subHour()]);
        relayDelivery(7200, ['status' => 'failed', 'attempts' => 6, 'completed_at' => now()->subHour()]);

        relay();

        Queue::assertNothingPushed();
    });

    it('queues a delivery once while its lease lasts, and again once the lease has run out', function () {
        $id = relayDelivery(600);

        relay();
        relay();
        Carbon::setTestNow(now()->addSeconds(200));
        relay();

        expect(relayedDeliveries())->toBe([$id]);

        Carbon::setTestNow(now()->addSeconds(200));
        relay();

        expect(relayedDeliveries())->toBe([$id, $id]);
    });

    it('does not queue a delivery that another relay claimed between the read and the claim', function () {
        $id = relayDelivery(600);
        $event = 'eloquent.retrieved: '.WebhookDelivery::class;
        Event::listen($event, function () use ($id) {
            // The other relay wins the claim as this one is about to make it.
            DB::table('webhook_deliveries')->where('id', $id)->update(['next_attempt_at' => now()]);
        });

        relay();

        Queue::assertNothingPushed();
        Event::forget($event);
    });

    it('reads and queues every account\'s deliveries under that account', function () {
        $other = Account::factory()->create();
        $otherConfig = inAccount($other, fn () => AvatarConfig::factory()->create());
        $otherConversation = inAccount($other, fn () => Conversation::factory()->create(['avatar_config_id' => $otherConfig->id]));
        $otherEndpoint = inAccount($other, fn () => WebhookEndpoint::factory()->create());
        $mine = relayDelivery(600);
        $theirs = relayDelivery(600, [], $other, $otherConversation, $otherEndpoint);

        relay();

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->deliveryId === $mine && $job->accountId === $this->account->id);
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->deliveryId === $theirs && $job->accountId === $other->id);
        Queue::assertPushed(DeliverWebhook::class, 2);
    });
});

describe('the losses it exists for', function () {
    it('delivers an event whose job was lost right after the dispatcher committed it', function () {
        [$id] = app(WebhookDispatcher::class)->record($this->conversation, EventType::ConversationEnded);
        Queue::fake();
        // Recording it again is a no-op and queues nothing: only the relay can find the delivery now.
        app(WebhookDispatcher::class)->record($this->conversation, EventType::ConversationEnded);
        Queue::assertNothingPushed();

        Carbon::setTestNow(now()->addSeconds(RelayLostJobs::STALE_SECONDS + 1));
        relay();

        expect(relayedDeliveries())->toBe([$id]);
    });

    it('delivers a retry that was written and never queued, then closes it like any other', function () {
        [$id] = app(WebhookDispatcher::class)->record($this->conversation, EventType::ConversationEnded);
        Http::fake(['*' => Http::sequence()->push('', 500)->push('', 204)]);
        app()->call([new DeliverWebhook($this->account->id, $id), 'handle']);
        // The retry row says "next attempt in 10 s"; the job that was to carry it is lost.
        Queue::fake();
        Carbon::setTestNow(now()->addSeconds(10 + RelayLostJobs::STALE_SECONDS + 1));

        relay();
        app()->call([new DeliverWebhook($this->account->id, $id), 'handle']);

        expect(relayedDeliveries())->toBe([$id])
            ->and(WebhookDelivery::withoutGlobalScopes()->findOrFail($id)->status)->toBe(DeliveryStatus::Succeeded)
            ->and(WebhookDelivery::withoutGlobalScopes()->findOrFail($id)->attempts)->toBe(2);
    });
});

describe('provider receipts', function () {
    it('queues an unprocessed receipt whose processing was never queued or never finished', function () {
        $id = relayReceipt(600);

        relay();

        Queue::assertPushed(ProcessProviderEvent::class, fn (ProcessProviderEvent $job) => $job->receiptId === $id && $job->accountId === $this->account->id);
        Queue::assertPushed(ProcessProviderEvent::class, 1);
    });

    it('leaves alone a young receipt, a processed one, and one past the age the relay gives up at', function () {
        relayReceipt(20);
        relayReceipt(600, ['processed_at' => now()->subMinutes(5)]);
        relayReceipt(90000);

        relay();

        Queue::assertNothingPushed();
    });

    it('queues a receipt once while its lease lasts, and again once it has run out', function () {
        $id = relayReceipt(600);

        relay();
        relay();
        expect(relayedReceipts())->toBe([$id]);

        Carbon::setTestNow(now()->addSeconds(90));
        relay();

        expect(relayedReceipts())->toBe([$id, $id]);
    });

    it('does not queue a receipt that another relay claimed between the read and the claim', function () {
        $id = relayReceipt(600);
        $event = 'eloquent.retrieved: '.ProviderWebhookReceipt::class;
        Event::listen($event, function () use ($id) {
            DB::table('provider_webhook_receipts')->where('id', $id)->update(['updated_at' => now()]);
        });

        relay();

        Queue::assertNothingPushed();
        Event::forget($event);
    });

    it('queues the receipts of every account under that account', function () {
        $other = Account::factory()->create();
        $otherConfig = inAccount($other, fn () => AvatarConfig::factory()->create());
        $otherConversation = inAccount($other, fn () => Conversation::factory()->create(['avatar_config_id' => $otherConfig->id]));
        $theirs = relayReceipt(600, [], $other, $otherConversation);

        relay();

        Queue::assertPushed(ProcessProviderEvent::class, fn (ProcessProviderEvent $job) => $job->receiptId === $theirs && $job->accountId === $other->id);
    });
});

describe('a failure', function () {
    it('does not stop the others, is logged by kind only and makes the command fail', function () {
        relayDelivery(600);
        relayDelivery(600);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $calls = 0;
        Bus::shouldReceive('dispatch')->andReturnUsing(function () use (&$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('queue down: redis://secret-host');
            }

            return null;
        });

        $exit = relay();

        expect($exit)->toBe(1)->and($calls)->toBe(2)
            ->and(implode("\n", $logged))->toContain('Relay failed')->toContain(RuntimeException::class)->not->toContain('secret-host');
    });
});

describe('the command', function () {
    it('says what it queued', function () {
        relayDelivery(600);
        relayReceipt(600);

        relay();

        expect(Artisan::output())->toContain('Queued 1 delivery(ies) and 1 provider event(s).');
    });

    it('is scheduled every minute and never overlaps itself', function () {
        $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event) => str_contains($event->command ?? '', 'hra:relay'));

        expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue();
    });
});
