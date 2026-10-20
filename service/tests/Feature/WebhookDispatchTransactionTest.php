<?php

use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Outbound\DeliveryStatus;
use App\Webhooks\Outbound\EventType;
use App\Webhooks\Outbound\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * The queue here is the real synchronous one, not the fake: a fake records a push the moment it is made, so it
 * cannot tell a job queued after the commit from one queued before. With the synchronous driver, "queued after
 * commit" shows as "the request is made after commit".
 */
beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->endpoint = inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
    $config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    $this->conversation = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id]));
});

function deliveriesOfConversation(Conversation $conversation): int
{
    return WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->count();
}

it('inserts the rows inside the caller\'s transaction and sends only once it commits', function () {
    Http::fake(['*' => Http::response('', 204)]);
    DB::transaction(function () {
        app(WebhookDispatcher::class)->record($this->conversation, EventType::ConversationLive);

        expect(deliveriesOfConversation($this->conversation))->toBe(1);
        Http::assertNothingSent();
    });

    Http::assertSentCount(1);
    expect(WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $this->conversation->id)->value('status'))->toBe(DeliveryStatus::Succeeded);
});

it('leaves nothing behind when the caller\'s transaction rolls back: no row, no request', function () {
    Http::fake(['*' => Http::response('', 204)]);
    try {
        DB::transaction(function () {
            app(WebhookDispatcher::class)->record($this->conversation, EventType::ConversationLive);

            throw new RuntimeException('the state change failed');
        });
    } catch (RuntimeException) {
    }

    expect(deliveriesOfConversation($this->conversation))->toBe(0);
    Http::assertNothingSent();
});

it('does not hold up the caller for a receiver that fails: the transaction commits and the failure is the delivery\'s', function () {
    Http::fake(['*' => Http::response('', 500)]);

    DB::transaction(fn () => app(WebhookDispatcher::class)->record($this->conversation, EventType::ConversationEnded));

    // Nothing was thrown at the caller. The synchronous queue ignores the retry delays, so every attempt ran, one after the other.
    $row = WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $this->conversation->id)->first();
    expect($row->status)->toBe(DeliveryStatus::Failed)->and($row->attempts)->toBe(6)->and($row->last_error)->toBe('http_500');
    Http::assertSentCount(6);
});
