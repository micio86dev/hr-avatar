<?php

use App\Jobs\DeliverWebhook;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Outbound\EventType;
use App\Webhooks\Outbound\WebhookDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 12:00:00');
    $this->account = Account::factory()->create();
    $this->endpoint = inAccount($this->account, fn () => WebhookEndpoint::factory()->create(['url' => 'https://hooks.example.com/hra?token=URL-TOKEN']));
    $config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    $this->conversation = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id, 'external_ref' => 'HOST-REFERENCE']));
});

describe('the signing secret', function () {
    it('appears in no log line across a whole retry cycle, whatever the receiver or the transport says', function () {
        Queue::fake();
        $logs = captureLogs();
        $secret = $this->endpoint->secret;
        $ids = app(WebhookDispatcher::class)->record($this->conversation, EventType::ConversationEnded);
        Http::fake(['*' => Http::sequence()
            ->push("echoing {$secret} back", 500)
            ->pushFailedConnection("cURL error 7: failed to connect to hooks.example.com with {$secret}")
            ->push(['secret' => $secret], 400)
            ->push('', 200)]);

        foreach (range(1, 4) as $ignored) {
            app()->call([new DeliverWebhook($this->account->id, $ids[0]), 'handle']);
        }
        (new DeliverWebhook($this->account->id, $ids[0]))->failed(new RuntimeException("crashed with {$secret}"));

        $everything = implode("\n", $logs->getArrayCopy());
        expect($everything)->not->toContain($secret)->not->toContain('hra_whsec_')->not->toContain('URL-TOKEN')->not->toContain('HOST-REFERENCE');
        $row = WebhookDelivery::withoutGlobalScopes()->find($ids[0]);
        expect(json_encode($row->only(['last_error', 'last_response_status', 'status'])))->not->toContain($secret);
    });

    it('is not in the record of a failed job: the job carries the account and delivery ids only', function () {
        Queue::fake();
        $ids = app(WebhookDispatcher::class)->record($this->conversation, EventType::ConversationEnded);

        $job = Queue::pushed(DeliverWebhook::class)->first();
        $record = serialize($job).json_encode(get_object_vars($job));

        expect($job->deliveryId)->toBe($ids[0])
            ->and($record)->not->toContain($this->endpoint->secret)->not->toContain('hra_whsec_')->not->toContain('URL-TOKEN')->not->toContain($this->endpoint->url);
    });

    it('is encrypted in the table and absent from the delivery table', function () {
        Queue::fake();
        app(WebhookDispatcher::class)->record($this->conversation, EventType::ConversationEnded);

        $dump = json_encode(DB::table('webhook_deliveries')->get()).json_encode(DB::table('webhook_endpoints')->get());

        expect($dump)->not->toContain($this->endpoint->secret);
    });
});

describe('the delivery payload', function () {
    it('is hidden from serialization and returned by no endpoint of the API', function () {
        Queue::fake();
        $ids = app(WebhookDispatcher::class)->record($this->conversation, EventType::ConversationEnded);
        [, $raw] = issueKey($this->account);
        $headers = ['Authorization' => 'Bearer '.$raw];

        $bodies = [
            $this->getJson("/v1/webhook-endpoints/{$this->endpoint->id}/deliveries", $headers)->getContent(),
            $this->getJson("/v1/webhook-endpoints/{$this->endpoint->id}", $headers)->getContent(),
            $this->getJson('/v1/webhook-endpoints', $headers)->getContent(),
        ];

        foreach ($bodies as $body) {
            // The host reference travels only inside the stored payload.
            expect($body)->not->toContain('HOST-REFERENCE')->not->toContain('"payload"')->not->toContain($this->conversation->id);
        }
        expect(json_encode(WebhookDelivery::withoutGlobalScopes()->find($ids[0])))->not->toContain('HOST-REFERENCE');
    });
});
