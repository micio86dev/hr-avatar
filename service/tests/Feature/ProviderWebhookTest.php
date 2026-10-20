<?php

use App\AvatarProviders\Ingestion;
use App\AvatarProviders\MalformedCallback;
use App\Conversations\ConversationStatus;
use App\Jobs\ProcessProviderEvent;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ProviderWebhookReceipt;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\AvatarProviders\ScriptedProvider;

beforeEach(function () {
    Queue::fake();
    $this->account = Account::factory()->create();
    $this->token = bin2hex(random_bytes(32));
    $this->conversation = callbackConversation($this->account, $this->token);
});

describe('a valid token and a matching conversation id', function () {
    it('answers 200 {"received":true}, stores the receipt for the conversation and account, and queues its processing', function () {
        $response = postInbound('tavus', $this->token, inboundCallback());

        $response->assertOk()->assertExactJson(['received' => true]);
        $receipt = ProviderWebhookReceipt::withoutGlobalScopes()->firstOrFail();
        expect($receipt->conversation_id)->toBe($this->conversation->id)
            ->and($receipt->account_id)->toBe($this->account->id)
            ->and($receipt->provider)->toBe('tavus')
            ->and($receipt->kind->value)->toBe('ended')
            ->and($receipt->provider_end_reason)->toBe('participant_left');
        Queue::assertPushed(ProcessProviderEvent::class, 1);
    });

    it('needs no key of any kind', function () {
        postInbound('tavus', $this->token, inboundCallback())->assertOk();
        postInbound('tavus', $this->token, inboundCallback(['event_type' => 'system.pal_joined']), ['HTTP_AUTHORIZATION' => 'Bearer not-a-key'])->assertOk();
    });

    it('stores and acknowledges an event it takes no action on, queueing nothing', function () {
        postInbound('tavus', $this->token, inboundCallback(['event_type' => 'application.recording_ready']))->assertOk()->assertExactJson(['received' => true]);

        expect(inboundReceiptCount())->toBe(1)->and(ProviderWebhookReceipt::withoutGlobalScopes()->firstOrFail()->kind->value)->toBe('ignored');
        Queue::assertNothingPushed();
    });

    it('stores the payload as sent, key order and unknown members included', function () {
        postInbound('tavus', $this->token, inboundCallback(['properties' => ['shutdown_reason' => 'participant_left', 'extra' => ['a' => [1, 2]]]]))->assertOk();

        expect(ProviderWebhookReceipt::withoutGlobalScopes()->firstOrFail()->payload['properties']['extra'])->toBe(['a' => [1, 2]]);
    });
});

describe('a request that cannot be authenticated', function () {
    /** The status, code and body shape of a refusal, with the one member that differs by design (the request id) left out. */
    function refusalShape(TestResponse $response): array
    {
        $body = $response->json();
        unset($body['request_id']);

        return [$response->status(), $response->headers->get('Content-Type'), $body];
    }

    it('is the same 404 not_found for every failure, and persists nothing', function () {
        $other = bin2hex(random_bytes(32));
        callbackConversation(Account::factory()->create(), $other, 'other-session');
        $attempts = [
            'unknown token' => postInbound('tavus', bin2hex(random_bytes(32)), inboundCallback()),
            'token of another conversation' => postInbound('tavus', $other, inboundCallback()),
            'unsupported provider segment' => postInbound('heygen', $this->token, inboundCallback()),
            'the mock provider' => postInbound('mock', $this->token, inboundCallback()),
            'an unknown provider' => postInbound('nope', $this->token, inboundCallback()),
            'token too short' => postInbound('tavus', substr($this->token, 0, 63), inboundCallback()),
            'token too long' => postInbound('tavus', $this->token.'0', inboundCallback()),
            'token in upper case' => postInbound('tavus', strtoupper($this->token), inboundCallback()),
            'token not hex' => postInbound('tavus', str_repeat('z', 64), inboundCallback()),
            'another conversation\'s session id' => postInbound('tavus', $this->token, inboundCallback(['conversation_id' => 'other-session'])),
            'a session id that is no one\'s' => postInbound('tavus', $this->token, inboundCallback(['conversation_id' => 'nobody'])),
        ];

        $shapes = collect($attempts)->map(fn ($response) => refusalShape($response));
        expect($shapes->unique()->count())->toBe(1);
        [$status, $type, $body] = $shapes->first();
        expect($status)->toBe(404)->and($type)->toBe('application/problem+json')->and($body['code'])->toBe('not_found')
            ->and(inboundReceiptCount())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('is a 404 also for a body that would be malformed: the body is judged only after the token', function () {
        postInbound('tavus', bin2hex(random_bytes(32)), 'not json')->assertNotFound()->assertJsonPath('code', 'not_found');
        postInbound('heygen', $this->token, [])->assertNotFound();
    });

    it('leaves a conversation whose token it was not, and the one it was, untouched', function () {
        $other = bin2hex(random_bytes(32));
        $mine = callbackConversation(Account::factory()->create(), $other, 'other-session');

        postInbound('tavus', $other, inboundCallback())->assertNotFound();

        expect(Conversation::withoutGlobalScopes()->findOrFail($mine->id)->status)->toBe(ConversationStatus::Connecting)
            ->and(Conversation::withoutGlobalScopes()->findOrFail($this->conversation->id)->status)->toBe(ConversationStatus::Connecting);
    });

    it('does not accept a conversation that has no token', function () {
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
        inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id, 'provider' => 'tavus', 'provider_session_ref' => 'tokenless']));

        postInbound('tavus', hash('sha256', ''), inboundCallback(['conversation_id' => 'tokenless']))->assertNotFound();
    });

    it('accepts only the provider the conversation belongs to', function () {
        Conversation::withoutGlobalScopes()->whereKey($this->conversation->id)->update(['provider' => 'mock']);

        postInbound('tavus', $this->token, inboundCallback())->assertNotFound();
    });
});

describe('a body that is malformed once the token is good', function () {
    it('is a 422 validation_failed that stores nothing', function (string|array $body) {
        $response = postInbound('tavus', $this->token, $body);

        $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        expect($response->headers->get('Content-Type'))->toBe('application/problem+json')
            ->and(inboundReceiptCount())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'not json' => ['not json'],
        'empty body' => [''],
        'a json scalar' => ['"text"'],
        'a json list' => ['[1,2]'],
        'no event type' => [fn () => Arr::except(inboundCallback(), 'event_type')],
        'no conversation id' => [fn () => Arr::except(inboundCallback(), 'conversation_id')],
        'no timestamp' => [fn () => Arr::except(inboundCallback(), 'timestamp')],
        'event type not a string' => [fn () => inboundCallback(['event_type' => ['x']])],
        'empty conversation id' => [fn () => inboundCallback(['conversation_id' => ''])],
    ]);

    it('names the missing members', function () {
        $response = postInbound('tavus', $this->token, Arr::except(inboundCallback(), ['event_type', 'timestamp']));

        expect(array_keys($response->json('errors')))->toBe(['event_type', 'timestamp']);
    });

    it('reads a form-encoded body as malformed', function () {
        $response = test()->call('POST', "/v1/webhooks/providers/tavus/{$this->token}", inboundCallback());

        $response->assertStatus(422);
        expect(inboundReceiptCount())->toBe(0);
    });
});

describe('a body the adapter cannot read once the token and the envelope are good', function () {
    it('is a 422 validation_failed that stores nothing, when the event time is not one a database can hold', function (string $timestamp) {
        $response = postInbound('tavus', $this->token, inboundCallback(['timestamp' => $timestamp]));

        $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        expect(inboundReceiptCount())->toBe(0);
        Queue::assertNothingPushed();
    })->with(['a year of eleven digits' => 'now +99999999999999999999 years', 'a year before year 1' => 'now -99999999999999999999 years']);

    it('is a 422 validation_failed when the adapter says the callback is malformed, without repeating what it said', function () {
        ScriptedProvider::register();
        ScriptedProvider::$ingestion = Ingestion::Callback;
        ScriptedProvider::$parse = fn () => throw new MalformedCallback('secret detail the provider sent');
        $token = bin2hex(random_bytes(32));
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create(['provider' => 'scripted']));
        inAccount($this->account, fn () => Conversation::factory()->create([
            'avatar_config_id' => $config->id, 'provider' => 'scripted', 'status' => ConversationStatus::Connecting, 'connecting_at' => now(),
            'provider_session_ref' => 'c9f2a1c7e4b3d4a1', 'callback_token_hash' => hash('sha256', $token),
        ]));

        $response = postInbound('scripted', $token, inboundCallback());

        $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        expect($response->getContent())->not->toContain('secret detail')->and(inboundReceiptCount())->toBe(0);
    });
});

describe('the plain token', function () {
    it('is never stored: not in any column of any table', function () {
        postInbound('tavus', $this->token, inboundCallback())->assertOk();

        $found = [];
        foreach (DB::select('select table_name from information_schema.tables where table_schema = current_schema() and table_type = ?', ['BASE TABLE']) as $table) {
            $rows = DB::select(sprintf('select t::text as row from "%s" t', $table->table_name));
            if (collect($rows)->contains(fn ($row) => str_contains($row->row, $this->token))) {
                $found[] = $table->table_name;
            }
        }

        expect($found)->toBe([]);
    });

    it('is taken out of a payload that echoes the callback address', function () {
        $echoed = "https://hra.example.test/v1/webhooks/providers/tavus/{$this->token}";

        postInbound('tavus', $this->token, inboundCallback(['webhook_url' => $echoed, 'properties' => ['shutdown_reason' => 'participant_left', 'note' => "see {$this->token}"]]))->assertOk();

        $payload = json_encode(ProviderWebhookReceipt::withoutGlobalScopes()->firstOrFail()->payload, JSON_THROW_ON_ERROR);
        expect($payload)->not->toContain($this->token)->and($payload)->toContain('participant_left');
    });
});

describe('the answer', function () {
    it('carries a request id, and the one the caller sent when it is well formed', function () {
        postInbound('tavus', $this->token, inboundCallback(), ['HTTP_X_REQUEST_ID' => 'prov-1'])->assertOk()->assertHeader('X-Request-Id', 'prov-1');
    });

    it('is documented as an unauthenticated operation with the token as a path parameter', function () {
        $document = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/webhooks/providers/{provider}/{token}']['post'] ?? null;

        expect($operation)->not->toBeNull()
            ->and($operation['security'] ?? [])->toBe([])
            ->and(collect($operation['parameters'])->pluck('name')->all())->toContain('provider', 'token');
    });
});
