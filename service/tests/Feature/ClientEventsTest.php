<?php

use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\EventIngestor;
use App\Keys\ClientSecret;
use App\Keys\KeyType;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-10-19 12:00:00');
    $this->account = Account::factory()->create();
    [, $this->pk] = issueKey($this->account, type: KeyType::Publishable);
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
});

/**
 * A conversation of the test account in the given state.
 *
 * @param  array<string, mixed>  $attributes
 */
function eventsConversation(ConversationStatus $status = ConversationStatus::Live, array $attributes = []): Conversation
{
    $implied = match ($status) {
        ConversationStatus::Created => [],
        ConversationStatus::Connecting => ['connecting_at' => now()->subMinute(), 'provider_session_ref' => 'sess-1'],
        ConversationStatus::Live => ['connecting_at' => now()->subMinutes(2), 'live_at' => now()->subMinute(), 'provider_session_ref' => 'sess-1'],
        ConversationStatus::Ended => ['connecting_at' => now()->subMinutes(5), 'ended_at' => now()->subSeconds(30), 'ended_reason' => EndedReason::Client, 'provider_session_ref' => 'sess-1'],
        ConversationStatus::Failed => ['connecting_at' => now()->subMinutes(5), 'ended_at' => now()->subSeconds(30), 'ended_reason' => EndedReason::ProviderError],
    };

    return inAccount(test()->account, fn () => Conversation::factory()->create([
        'avatar_config_id' => test()->config->id, 'status' => $status, ...$implied, ...$attributes,
    ]));
}

/**
 * @return array{idempotency_key: string, type: string, role: string, text: string}
 */
function utteranceEvent(string $key, string $text = 'Hello', string $role = 'user'): array
{
    return ['idempotency_key' => $key, 'type' => 'utterance', 'role' => $role, 'text' => $text];
}

/**
 * @return array{idempotency_key: string, type: string, name: string}
 */
function lifecycleEvent(string $key, string $name = 'live'): array
{
    return ['idempotency_key' => $key, 'type' => 'lifecycle', 'name' => $name];
}

/**
 * @param  array<array-key, mixed>|string  $events
 */
function postEvents(Conversation $conversation, array|string $events, ?string $secret = null): TestResponse
{
    return test()->postJson(
        "/v1/client/conversations/{$conversation->id}/events",
        is_string($events) ? ['events' => $events] : ['events' => $events],
        browserHeaders(test()->pk, $secret ?? clientSecretFor($conversation)),
    );
}

/**
 * @return list<ConversationEvent>
 */
function storedEvents(Conversation $conversation): array
{
    return ConversationEvent::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('sequence')->get()->all();
}

function eventsRefreshed(Conversation $conversation): Conversation
{
    return Conversation::withoutGlobalScopes()->findOrFail($conversation->id);
}

describe('storing a batch', function () {
    it('stores the events in batch order with a sequence the server assigns', function () {
        $conversation = eventsConversation();

        $response = postEvents($conversation, [utteranceEvent('k1', 'One'), utteranceEvent('k2', 'Two', 'avatar'), utteranceEvent('k3', 'Three')])->assertOk();

        $stored = storedEvents($conversation);
        expect($response->json())->toBe(['accepted' => 3, 'duplicates' => 0])
            ->and(array_map(fn ($e) => [$e->sequence, $e->source, $e->type, $e->role, $e->text, $e->idempotency_key], $stored))
            ->toBe([[1, 'client', 'utterance', 'user', 'One', 'k1'], [2, 'client', 'utterance', 'avatar', 'Two', 'k2'], [3, 'client', 'utterance', 'user', 'Three', 'k3']])
            ->and($stored[0]->account_id)->toBe($this->account->id);
    });

    it('continues the numbering after whatever the conversation already holds', function () {
        $conversation = eventsConversation();
        inAccount($this->account, fn () => ConversationEvent::forceCreate([
            'conversation_id' => $conversation->id, 'sequence' => 7, 'source' => 'provider', 'type' => 'utterance', 'role' => 'avatar', 'text' => 'Earlier', 'idempotency_key' => 'provider:0',
        ]));

        postEvents($conversation, [utteranceEvent('k1'), lifecycleEvent('k2', 'error')])->assertOk();

        expect(array_map(fn ($e) => $e->sequence, storedEvents($conversation)))->toBe([7, 8, 9]);
    });

    it('takes the sequence and the source from the service, whatever the body says', function () {
        $conversation = eventsConversation();

        postEvents($conversation, [[...utteranceEvent('k1'), 'sequence' => 99, 'source' => 'provider', 'account_id' => 'forged']])->assertOk();

        $stored = storedEvents($conversation)[0];
        expect([$stored->sequence, $stored->source, $stored->account_id])->toBe([1, 'client', $this->account->id]);
    });

    it('keeps the time the browser gave, in UTC, and none when it gave none', function () {
        $conversation = eventsConversation();

        postEvents($conversation, [
            [...utteranceEvent('k1'), 'occurred_at' => '2026-10-19T14:30:00+02:00'],
            [...utteranceEvent('k2'), 'occurred_at' => '2026-10-19T12:31:00.250Z'],
            utteranceEvent('k3'),
        ])->assertOk();

        $stored = storedEvents($conversation);
        expect($stored[0]->occurred_at?->utc()->toIso8601String())->toBe('2026-10-19T12:30:00+00:00')
            ->and($stored[1]->occurred_at?->utc()->format('Y-m-d H:i:s.v'))->toBe('2026-10-19 12:31:00.250')
            ->and($stored[2]->occurred_at)->toBeNull();
    });

    it('keeps the data of a lifecycle event as an object, an empty one included', function () {
        $conversation = eventsConversation();

        postEvents($conversation, [
            [...lifecycleEvent('k1', 'error'), 'data' => ['code' => 'ice_failed', 'detail' => ['attempt' => 2]]],
            [...lifecycleEvent('k2', 'error'), 'data' => new stdClass],
        ])->assertOk();

        $raw = DB::table('conversation_events')->where('conversation_id', $conversation->id)->orderBy('sequence')->pluck('data')->all();
        expect(json_decode($raw[0], true))->toBe(['code' => 'ice_failed', 'detail' => ['attempt' => 2]])
            ->and($raw[1])->toBe('{}');
    });

    it('keeps the same key of another conversation apart', function () {
        $first = eventsConversation();
        $second = eventsConversation();

        postEvents($first, [utteranceEvent('same')])->assertOk()->assertJson(['accepted' => 1]);
        postEvents($second, [utteranceEvent('same')])->assertOk()->assertJson(['accepted' => 1, 'duplicates' => 0]);

        expect(storedEvents($first))->toHaveCount(1)->and(storedEvents($second))->toHaveCount(1);
    });

    it('locks the conversation row while it numbers the events', function () {
        $conversation = eventsConversation();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        postEvents($conversation, [utteranceEvent('k1')])->assertOk();

        expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'from "conversations"') && str_contains($sql, 'for update')))->toBeTrue();
    });
});

describe('replaying a batch', function () {
    it('stores nothing the second time and counts the whole batch as duplicates', function () {
        $conversation = eventsConversation();
        $batch = [utteranceEvent('k1'), utteranceEvent('k2'), utteranceEvent('k3')];
        postEvents($conversation, $batch)->assertOk();

        $response = postEvents($conversation, $batch)->assertOk();

        expect($response->json())->toBe(['accepted' => 0, 'duplicates' => 3])->and(storedEvents($conversation))->toHaveCount(3);
    });

    it('stores only the new key of a batch that overlaps an earlier one', function () {
        $conversation = eventsConversation();
        postEvents($conversation, [utteranceEvent('k1'), utteranceEvent('k2')])->assertOk();

        $response = postEvents($conversation, [utteranceEvent('k1'), utteranceEvent('k3'), utteranceEvent('k2')])->assertOk();

        expect($response->json())->toBe(['accepted' => 1, 'duplicates' => 2])
            ->and(array_map(fn ($e) => [$e->sequence, $e->idempotency_key], storedEvents($conversation)))->toBe([[1, 'k1'], [2, 'k2'], [3, 'k3']]);
    });

    it('counts a key repeated inside one batch as a duplicate and stores it once', function () {
        $conversation = eventsConversation();

        $response = postEvents($conversation, [utteranceEvent('k1', 'First'), utteranceEvent('k1', 'Second')])->assertOk();

        expect($response->json())->toBe(['accepted' => 1, 'duplicates' => 1])
            ->and(storedEvents($conversation)[0]->text)->toBe('First');
    });
});

describe('validating a batch', function () {
    it('rejects the whole batch when one event is invalid, and stores nothing', function () {
        $conversation = eventsConversation();

        $response = postEvents($conversation, [utteranceEvent('k1'), utteranceEvent('k2'), [...utteranceEvent('k3'), 'text' => '']])->assertStatus(422);

        expect($response->json('code'))->toBe('validation_failed')
            ->and(array_keys($response->json('errors')))->toBe(['events.2.text'])
            ->and(storedEvents($conversation))->toBe([]);
    });

    it('refuses an event that is not well formed', function (array $event, string $field) {
        $conversation = eventsConversation();

        $response = postEvents($conversation, [utteranceEvent('k0'), $event])->assertStatus(422);

        expect(array_keys($response->json('errors')))->toContain($field)->and(storedEvents($conversation))->toBe([]);
    })->with([
        'no key' => [['type' => 'utterance', 'role' => 'user', 'text' => 'Hi'], 'events.1.idempotency_key'],
        'a key of 129 characters' => [['idempotency_key' => str_repeat('k', 129), 'type' => 'utterance', 'role' => 'user', 'text' => 'Hi'], 'events.1.idempotency_key'],
        'an unknown type' => [['idempotency_key' => 'k', 'type' => 'gesture'], 'events.1.type'],
        'no type' => [['idempotency_key' => 'k', 'role' => 'user', 'text' => 'Hi'], 'events.1.type'],
        'an utterance without a role' => [['idempotency_key' => 'k', 'type' => 'utterance', 'text' => 'Hi'], 'events.1.role'],
        'an utterance of an unknown role' => [['idempotency_key' => 'k', 'type' => 'utterance', 'role' => 'system', 'text' => 'Hi'], 'events.1.role'],
        'an utterance without text' => [['idempotency_key' => 'k', 'type' => 'utterance', 'role' => 'user'], 'events.1.text'],
        'text of only whitespace' => [['idempotency_key' => 'k', 'type' => 'utterance', 'role' => 'user', 'text' => '   '], 'events.1.text'],
        'text of 8001 characters' => [['idempotency_key' => 'k', 'type' => 'utterance', 'role' => 'user', 'text' => str_repeat('a', 8001)], 'events.1.text'],
        'an utterance with a lifecycle name' => [['idempotency_key' => 'k', 'type' => 'utterance', 'role' => 'user', 'text' => 'Hi', 'name' => 'live'], 'events.1.name'],
        'a lifecycle without a name' => [['idempotency_key' => 'k', 'type' => 'lifecycle'], 'events.1.name'],
        'a lifecycle of an unknown name' => [['idempotency_key' => 'k', 'type' => 'lifecycle', 'name' => 'ended'], 'events.1.name'],
        'a lifecycle with text' => [['idempotency_key' => 'k', 'type' => 'lifecycle', 'name' => 'live', 'text' => 'Hi'], 'events.1.text'],
        'a lifecycle with a role' => [['idempotency_key' => 'k', 'type' => 'lifecycle', 'name' => 'live', 'role' => 'user'], 'events.1.role'],
        'a time with no zone' => [['idempotency_key' => 'k', 'type' => 'utterance', 'role' => 'user', 'text' => 'Hi', 'occurred_at' => '2026-10-19T12:00:00'], 'events.1.occurred_at'],
        'a time in words' => [['idempotency_key' => 'k', 'type' => 'utterance', 'role' => 'user', 'text' => 'Hi', 'occurred_at' => 'tomorrow'], 'events.1.occurred_at'],
        'a time that is not a date' => [['idempotency_key' => 'k', 'type' => 'utterance', 'role' => 'user', 'text' => 'Hi', 'occurred_at' => '2026-13-45T25:61:00Z'], 'events.1.occurred_at'],
        // The column holds years 1 to 9999 in UTC: these would pass the pattern and fail the insert (a 500).
        'year zero' => [['idempotency_key' => 'k', 'type' => 'utterance', 'role' => 'user', 'text' => 'Hi', 'occurred_at' => '0000-01-01T00:00:00Z'], 'events.1.occurred_at'],
        'a year that is 10000 in UTC' => [['idempotency_key' => 'k', 'type' => 'utterance', 'role' => 'user', 'text' => 'Hi', 'occurred_at' => '9999-12-31T23:00:00-01:00'], 'events.1.occurred_at'],
        'a year before 1 in UTC' => [['idempotency_key' => 'k', 'type' => 'utterance', 'role' => 'user', 'text' => 'Hi', 'occurred_at' => '0001-01-01T00:00:00+01:00'], 'events.1.occurred_at'],
        'data that is a list' => [['idempotency_key' => 'k', 'type' => 'lifecycle', 'name' => 'error', 'data' => [1, 2]], 'events.1.data'],
        'data that is a scalar' => [['idempotency_key' => 'k', 'type' => 'lifecycle', 'name' => 'error', 'data' => 'oops'], 'events.1.data'],
        'data above 2 KB' => [['idempotency_key' => 'k', 'type' => 'lifecycle', 'name' => 'error', 'data' => ['blob' => str_repeat('a', 2100)]], 'events.1.data'],
    ]);

    it('accepts the first and the last instant the column can hold', function () {
        $conversation = eventsConversation();

        postEvents($conversation, [[...utteranceEvent('k1'), 'occurred_at' => '0001-01-01T00:00:00Z'], [...utteranceEvent('k2'), 'occurred_at' => '9999-12-31T23:59:59.999999Z']])->assertOk();

        expect(storedEvents($conversation))->toHaveCount(2);
    });

    it('refuses a body that is not a list of events', function (mixed $events) {
        $conversation = eventsConversation();

        postEvents($conversation, $events)->assertStatus(422);

        expect(storedEvents($conversation))->toBe([]);
    })->with(['none' => [[]], 'a string' => ['oops'], 'an object' => [['a' => utteranceEvent('k')]]]);

    it('takes a hundred events and refuses a hundred and one', function () {
        $conversation = eventsConversation();
        $events = fn (int $count) => array_map(fn (int $i) => utteranceEvent("k{$i}"), range(1, $count));

        postEvents($conversation, $events(101))->assertStatus(422);
        expect(storedEvents($conversation))->toBe([]);

        postEvents($conversation, $events(100))->assertOk()->assertJson(['accepted' => 100]);
        expect(storedEvents($conversation))->toHaveCount(100);
    });

    it('accepts text of exactly 8000 characters and data of exactly 2 KB', function () {
        $conversation = eventsConversation();
        $data = ['b' => str_repeat('a', 2048 - strlen('{"b":""}'))];
        expect(strlen(json_encode($data)))->toBe(2048);

        postEvents($conversation, [utteranceEvent('k1', str_repeat('a', 8000)), [...lifecycleEvent('k2', 'error'), 'data' => $data]])->assertOk();

        expect(storedEvents($conversation))->toHaveCount(2);
    });
});

describe('a body that is not JSON', function () {
    it('takes a form-encoded batch of events', function () {
        $conversation = eventsConversation();

        $this->post("/v1/client/conversations/{$conversation->id}/events", ['events' => [utteranceEvent('k1', 'Spoken'), lifecycleEvent('k2', 'error')]], [...browserHeaders($this->pk, clientSecretFor($conversation)), 'Accept' => 'application/json'])
            ->assertOk()->assertJson(['accepted' => 2, 'duplicates' => 0]);

        expect(array_map(fn ($e) => $e->text ?? $e->name, storedEvents($conversation)))->toBe(['Spoken', 'error']);
    });

    it('refuses data it cannot tell is an object', function () {
        $conversation = eventsConversation();

        $response = $this->post("/v1/client/conversations/{$conversation->id}/events", ['events' => [[...lifecycleEvent('k1', 'error'), 'data' => ['code' => 'x']]]], [...browserHeaders($this->pk, clientSecretFor($conversation)), 'Accept' => 'application/json'])
            ->assertStatus(422);

        expect(array_keys($response->json('errors')))->toBe(['events.0.data'])->and(storedEvents($conversation))->toBe([]);
    });
});

describe('when events are accepted', function () {
    it('takes events for a conversation that is connecting', function () {
        $conversation = eventsConversation(ConversationStatus::Connecting);

        postEvents($conversation, [utteranceEvent('k1')])->assertOk()->assertJson(['accepted' => 1]);
    });

    it('takes events for 60 seconds after the conversation ended, and not after', function (ConversationStatus $status) {
        $conversation = eventsConversation($status, ['ended_at' => now()->subSeconds(30)]);
        postEvents($conversation, [utteranceEvent('k1')])->assertOk()->assertJson(['accepted' => 1]);

        $this->travel(30)->seconds();
        postEvents($conversation, [utteranceEvent('k2')])->assertOk()->assertJson(['accepted' => 1]);

        $this->travel(1)->seconds();
        $late = postEvents($conversation, [utteranceEvent('k3')])->assertStatus(409);

        expect($late->json('code'))->toBe('conversation_closed')->and(storedEvents($conversation))->toHaveCount(2);
    })->with([ConversationStatus::Ended, ConversationStatus::Failed]);

    it('closes a conversation that ended 61 seconds ago to events, a replay included', function () {
        $conversation = eventsConversation(ConversationStatus::Ended, ['ended_at' => now()->subSeconds(61)]);

        $response = postEvents($conversation, [utteranceEvent('k1')])->assertStatus(409);

        expect($response->json('code'))->toBe('conversation_closed')->and(storedEvents($conversation))->toBe([]);
    });

    it('refuses an utterance before the conversation is connected, whole batch', function () {
        $conversation = eventsConversation(ConversationStatus::Created);

        $response = postEvents($conversation, [lifecycleEvent('k1'), utteranceEvent('k2')])->assertStatus(409);

        expect($response->json('code'))->toBe('invalid_state')->and(storedEvents($conversation))->toBe([]);
    });

    it('stores a live signal before the conversation is connected, without effect', function () {
        $conversation = eventsConversation(ConversationStatus::Created);

        $response = postEvents($conversation, [lifecycleEvent('k1')])->assertOk();

        expect($response->json())->toBe(['accepted' => 1, 'duplicates' => 0])
            ->and(eventsRefreshed($conversation)->status)->toBe(ConversationStatus::Created)
            ->and(storedEvents($conversation))->toHaveCount(1);
    });
});

describe('a replay after the conversation closed', function () {
    // A browser that flushed its last batch just before the minute ran out retries it: when every event of the batch is
    // already stored there is nothing left to refuse, and the retry must be answered as the replay it is.
    it('is answered as duplicates when every event is already stored', function () {
        $conversation = eventsConversation(ConversationStatus::Ended, ['ended_at' => now()->subSeconds(30)]);
        postEvents($conversation, [utteranceEvent('k1'), utteranceEvent('k2')])->assertOk();
        $this->travel(120)->seconds();

        $response = postEvents($conversation, [utteranceEvent('k1'), utteranceEvent('k2')])->assertOk();

        expect($response->json())->toBe(['accepted' => 0, 'duplicates' => 2])->and(storedEvents($conversation))->toHaveCount(2);
    });

    it('is refused whole when any event of the batch is new, and stores nothing of it', function () {
        $conversation = eventsConversation(ConversationStatus::Ended, ['ended_at' => now()->subSeconds(30)]);
        postEvents($conversation, [utteranceEvent('k1')])->assertOk();
        $this->travel(120)->seconds();

        $response = postEvents($conversation, [utteranceEvent('k1'), utteranceEvent('k-new')])->assertStatus(409);

        expect($response->json('code'))->toBe('conversation_closed')->and(storedEvents($conversation))->toHaveCount(1);
    });

    it('still refuses an utterance before the conversation is connected when it is a new one', function () {
        $conversation = eventsConversation(ConversationStatus::Created);

        $response = postEvents($conversation, [utteranceEvent('k1')])->assertStatus(409);

        expect($response->json('code'))->toBe('invalid_state');
    });
});

describe('what is stored of an event\'s data', function () {
    // The 2 KB limit is measured on the bytes that are stored, not on a different spelling of them.
    it('is the encoding the limit was measured on', function () {
        $conversation = eventsConversation();
        $bindings = [];
        DB::listen(function ($query) use (&$bindings) {
            if (str_starts_with($query->sql, 'insert into "conversation_events"')) {
                $bindings = $query->bindings;
            }
        });
        $data = ['t' => str_repeat('é', 700), 'u' => str_repeat('/', 300)];

        postEvents($conversation, [[...lifecycleEvent('k1', 'error'), 'data' => $data]])->assertOk();

        $stored = array_values(array_filter($bindings, fn ($binding) => is_string($binding) && str_starts_with($binding, '{"t":')))[0] ?? '';
        expect(strlen($stored))->toBeLessThanOrEqual(2048)->and(strlen($stored))->toBeGreaterThan(1400)
            ->and(json_decode($stored, true))->toBe($data)
            ->and(storedEvents($conversation)[0]->data)->not->toBeNull();
    });

    it('refuses data that is over 2 KB in the encoding that is stored', function () {
        $conversation = eventsConversation();

        $response = postEvents($conversation, [[...lifecycleEvent('k1', 'error'), 'data' => ['t' => str_repeat('é', 1100)]]])->assertStatus(422);

        expect(array_keys($response->json('errors')))->toBe(['events.0.data'])->and(storedEvents($conversation))->toBe([]);
    });
});

describe('how many events a conversation holds', function () {
    // The transcript endpoint returns every utterance of a conversation, so what a conversation may hold is bounded
    // where it is written. The sequence is dense (numbered from the highest stored), so it is the count.
    it('takes events up to the cap and refuses the one past it as the conversation being closed to events', function () {
        $conversation = eventsConversation();
        DB::table('conversation_events')->insert([
            'account_id' => $this->account->id, 'conversation_id' => $conversation->id, 'sequence' => EventIngestor::MAX_EVENTS - 1, 'source' => 'client',
            'type' => 'utterance', 'role' => 'user', 'text' => 'x', 'idempotency_key' => 'seed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        postEvents($conversation, [utteranceEvent('last')])->assertOk()->assertJson(['accepted' => 1]);
        $response = postEvents($conversation, [utteranceEvent('one-too-many')])->assertStatus(409);

        expect($response->json('code'))->toBe('conversation_closed')->and($response->json('detail'))->toContain('as many events')
            ->and(storedEvents($conversation))->toHaveCount(2);
    });

    it('refuses a batch that would cross the cap whole, and still answers a replay of what is stored', function () {
        $conversation = eventsConversation();
        DB::table('conversation_events')->insert([
            'account_id' => $this->account->id, 'conversation_id' => $conversation->id, 'sequence' => EventIngestor::MAX_EVENTS - 1, 'source' => 'client',
            'type' => 'utterance', 'role' => 'user', 'text' => 'x', 'idempotency_key' => 'seed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        postEvents($conversation, [utteranceEvent('a'), utteranceEvent('b')])->assertStatus(409);
        $replay = postEvents($conversation, [utteranceEvent('seed')])->assertOk();

        expect($replay->json())->toBe(['accepted' => 0, 'duplicates' => 1])->and(storedEvents($conversation))->toHaveCount(1);
    });
});

describe('lifecycle events', function () {
    $emitted = fn (Conversation $conversation) => WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->get()
        ->map(fn (WebhookDelivery $delivery) => $delivery->event_type->value)->all();

    it('makes a connecting conversation live and emits conversation.live once', function () use ($emitted) {
        $conversation = eventsConversation(ConversationStatus::Connecting);

        postEvents($conversation, [lifecycleEvent('k1')])->assertOk();

        $fresh = eventsRefreshed($conversation);
        expect($fresh->status)->toBe(ConversationStatus::Live)
            ->and($fresh->live_at?->toIso8601String())->toBe(now()->toIso8601String())
            ->and($emitted($conversation))->toBe(['conversation.live']);
    });

    it('does not make it live again on a replay or on another live signal', function () use ($emitted) {
        $conversation = eventsConversation(ConversationStatus::Connecting);
        postEvents($conversation, [lifecycleEvent('k1')])->assertOk();
        $liveAt = eventsRefreshed($conversation)->live_at;
        $this->travel(5)->seconds();

        postEvents($conversation, [lifecycleEvent('k1')])->assertOk()->assertJson(['accepted' => 0, 'duplicates' => 1]);
        postEvents($conversation, [lifecycleEvent('k2')])->assertOk()->assertJson(['accepted' => 1]);

        expect(eventsRefreshed($conversation)->live_at?->equalTo($liveAt))->toBeTrue()
            ->and($emitted($conversation))->toBe(['conversation.live'])
            ->and(storedEvents($conversation))->toHaveCount(2);
    });

    it('stores an error signal with its data and leaves the state alone', function (ConversationStatus $status) {
        $conversation = eventsConversation($status);

        postEvents($conversation, [[...lifecycleEvent('k1', 'error'), 'data' => ['code' => 'ice_failed']]])->assertOk();

        expect(eventsRefreshed($conversation)->status)->toBe($status)
            ->and(storedEvents($conversation)[0]->name)->toBe('error')
            ->and(storedEvents($conversation)[0]->data)->toBe(['code' => 'ice_failed']);
    })->with([ConversationStatus::Created, ConversationStatus::Connecting, ConversationStatus::Live]);

    it('stores a live signal that arrives after the end, without effect', function () use ($emitted) {
        $conversation = eventsConversation(ConversationStatus::Ended);

        postEvents($conversation, [lifecycleEvent('k1')])->assertOk()->assertJson(['accepted' => 1]);

        expect(eventsRefreshed($conversation)->status)->toBe(ConversationStatus::Ended)->and($emitted($conversation))->toBe([]);
    });

    it('applies the live signal of a batch together with the utterances after it', function () {
        $conversation = eventsConversation(ConversationStatus::Connecting);

        postEvents($conversation, [lifecycleEvent('k1'), utteranceEvent('k2')])->assertOk()->assertJson(['accepted' => 2]);

        expect(eventsRefreshed($conversation)->status)->toBe(ConversationStatus::Live)->and(storedEvents($conversation))->toHaveCount(2);
    });
});

describe('who may send events', function () {
    it('answers 404 for a conversation the key\'s account does not have, and stores nothing', function () {
        $stranger = Account::factory()->create();
        $foreign = inAccount($stranger, fn () => Conversation::factory()->create(['avatar_config_id' => inAccount($stranger, fn () => AvatarConfig::factory()->create())->id, 'status' => ConversationStatus::Live]));
        $secret = app(ClientSecret::class)->mint($foreign->id, $this->account->id, now(), 1800)->token;

        $response = postEvents($foreign, [utteranceEvent('k1')], $secret)->assertNotFound();

        expect($response->json('code'))->toBe('not_found')->and(storedEvents($foreign))->toBe([]);
    });

    it('answers 403 for the secret of another conversation, and 401 without one', function () {
        $first = eventsConversation();
        $second = eventsConversation();

        postEvents($first, [utteranceEvent('k1')], clientSecretFor($second))->assertForbidden();
        $this->postJson("/v1/client/conversations/{$first->id}/events", ['events' => [utteranceEvent('k1')]], ['X-HRA-Publishable-Key' => $this->pk, 'Origin' => 'https://app.example.com'])->assertUnauthorized();

        expect(storedEvents($first))->toBe([]);
    });
});
