<?php

use App\AvatarProviders\ProviderRegistry;
use App\Conversations\ConversationStatus;
use App\Conversations\ProviderEventIngestor;
use App\Jobs\ProcessProviderEvent;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ProviderWebhookReceipt;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->account = Account::factory()->create();
    $this->token = bin2hex(random_bytes(32));
    $this->conversation = callbackConversation($this->account, $this->token);
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
});

it('answers 200 to a duplicate, stores one receipt and queues one processing', function () {
    foreach (range(1, 3) as $ignored) {
        postInbound('tavus', $this->token, inboundCallback())->assertOk()->assertExactJson(['received' => true]);
    }

    expect(ProviderWebhookReceipt::withoutGlobalScopes()->count())->toBe(1);
    Queue::assertPushed(ProcessProviderEvent::class, 1);
});

it('makes one transition and emits one conversation.ended however often the callback arrives', function () {
    postInbound('tavus', $this->token, inboundCallback())->assertOk();
    postInbound('tavus', $this->token, inboundCallback())->assertOk();
    $pushed = Queue::pushed(ProcessProviderEvent::class);
    expect($pushed)->toHaveCount(1);

    // The queue delivers at least once: running the job twice changes nothing the second time.
    app()->call([$pushed->first(), 'handle']);
    app()->call([$pushed->first(), 'handle']);

    $conversation = Conversation::withoutGlobalScopes()->findOrFail($this->conversation->id);
    expect($conversation->status)->toBe(ConversationStatus::Ended)
        ->and($conversation->provider_end_reason)->toBe('participant_left')
        ->and(WebhookDelivery::withoutGlobalScopes()->where('event_type', 'conversation.ended')->count())->toBe(1);
});

it('treats a different event of the same conversation as a new one', function () {
    postInbound('tavus', $this->token, inboundCallback())->assertOk();
    postInbound('tavus', $this->token, inboundCallback(['timestamp' => '2026-10-19T12:10:01.000000Z']))->assertOk();
    postInbound('tavus', $this->token, inboundCallback(['event_type' => 'system.pal_joined']))->assertOk();

    expect(ProviderWebhookReceipt::withoutGlobalScopes()->count())->toBe(3);
});

// A second copy that reaches the unique key while the first is still being stored. The suite's single connection cannot
// run two requests at once, so the copy is delivered from inside the first one's transaction, right after its insert:
// the same interleaving the unique key exists for.
it('stores and queues one receipt when a second copy arrives while the first is still being stored', function () {
    $copy = app(ProviderRegistry::class)->get('tavus')->parseCallback(inboundCallback());
    $delivered = false;
    DB::listen(function ($query) use (&$delivered, $copy) {
        if (! $delivered && str_starts_with($query->sql, 'insert into "provider_webhook_receipts"')) {
            $delivered = true;
            expect(app(ProviderEventIngestor::class)->ingest($this->conversation, $copy))->toBe(0);
        }
    });

    postInbound('tavus', $this->token, inboundCallback())->assertOk()->assertExactJson(['received' => true]);

    expect($delivered)->toBeTrue()->and(ProviderWebhookReceipt::withoutGlobalScopes()->count())->toBe(1);
    Queue::assertPushed(ProcessProviderEvent::class, 1);
});

it('queues the processing again when the provider repeats a callback whose first processing never ran', function () {
    postInbound('tavus', $this->token, inboundCallback())->assertOk();
    Queue::fake();
    $this->travel(ProviderEventIngestor::STALE_SECONDS + 1)->seconds();

    postInbound('tavus', $this->token, inboundCallback())->assertOk()->assertExactJson(['received' => true]);

    expect(ProviderWebhookReceipt::withoutGlobalScopes()->count())->toBe(1);
    Queue::assertPushed(ProcessProviderEvent::class, 1);
});
