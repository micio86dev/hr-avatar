<?php

use App\AvatarProviders\ProviderEvent;
use App\AvatarProviders\ProviderEventKind;
use App\Conversations\ConversationStatus;
use App\Conversations\ProviderEventIngestor;
use App\Jobs\ProcessProviderEvent;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ProviderWebhookReceipt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    $this->conversation = receiptConversation();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function receiptConversation(array $attributes = [], ?Account $account = null): Conversation
{
    $account ??= test()->account;
    $config = $account->is(test()->account) ? test()->config : inAccount($account, fn () => AvatarConfig::factory()->create());

    return inAccount($account, fn () => Conversation::factory()->create([
        'avatar_config_id' => $config->id, 'provider' => 'tavus', 'status' => ConversationStatus::Connecting,
        'connecting_at' => now()->subMinute(), 'provider_session_ref' => 'c123', ...$attributes,
    ]));
}

/**
 * @param  array<string, mixed>  $payload
 */
function providerEvent(ProviderEventKind $kind = ProviderEventKind::Joined, string $key = 'key-1', ?string $reason = null, array $payload = ['event_type' => 'system.pal_joined'], string $provider = 'tavus'): ProviderEvent
{
    return new ProviderEvent($provider, 'c123', $kind, new DateTimeImmutable('2026-10-19T12:00:00.250Z'), $key, $reason, $payload);
}

/**
 * @return list<ProviderWebhookReceipt>
 */
function receipts(): array
{
    return ProviderWebhookReceipt::withoutGlobalScopes()->orderBy('created_at')->orderBy('id')->get()->all();
}

describe('storing an event', function () {
    beforeEach(fn () => Queue::fake());

    it('persists a receipt for the conversation and account it was authenticated against, then queues its processing', function () {
        $stored = app(ProviderEventIngestor::class)->ingest($this->conversation, [providerEvent(reason: null, payload: ['event_type' => 'system.pal_joined', 'properties' => ['a' => ['b' => 1]]])]);

        $receipt = receipts()[0];
        expect($stored)->toBe(1)
            ->and($receipt->account_id)->toBe($this->account->id)
            ->and($receipt->conversation_id)->toBe($this->conversation->id)
            ->and($receipt->provider)->toBe('tavus')
            ->and($receipt->provider_ref)->toBe('c123')
            ->and($receipt->idempotency_key)->toBe('key-1')
            ->and($receipt->kind)->toBe(ProviderEventKind::Joined)
            ->and($receipt->occurred_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-19 12:00:00.250')
            ->and($receipt->processed_at)->toBeNull()
            ->and($receipt->payload)->toBe(['event_type' => 'system.pal_joined', 'properties' => ['a' => ['b' => 1]]])
            ->and($receipt->id)->toMatch('/^[0-9a-z]{26}$/');
        Queue::assertPushed(ProcessProviderEvent::class, fn (ProcessProviderEvent $job) => $job->accountId === $this->account->id && $job->receiptId === $receipt->id);
        Queue::assertPushed(ProcessProviderEvent::class, 1);
    });

    it('keeps the provider\'s reason beside the event', function () {
        app(ProviderEventIngestor::class)->ingest($this->conversation, [providerEvent(ProviderEventKind::Ended, 'k-end', 'participant_left')]);

        expect(receipts()[0]->provider_end_reason)->toBe('participant_left');
    });

    it('stores an event of any kind it is given, a list of them in order', function () {
        $events = [providerEvent(ProviderEventKind::Joined, 'a'), providerEvent(ProviderEventKind::TranscriptReady, 'b'), providerEvent(ProviderEventKind::Ended, 'c', 'x')];

        $stored = app(ProviderEventIngestor::class)->ingest($this->conversation, $events);

        expect($stored)->toBe(3)->and(array_map(fn ($r) => $r->idempotency_key, receipts()))->toBe(['a', 'b', 'c']);
        Queue::assertPushed(ProcessProviderEvent::class, 3);
    });

    it('stores an empty payload as an object', function () {
        app(ProviderEventIngestor::class)->ingest($this->conversation, [providerEvent(payload: [])]);

        expect(DB::table('provider_webhook_receipts')->value('payload'))->toBe('{}');
    });
});

describe('an event that is not about the conversation\'s state', function () {
    beforeEach(fn () => Queue::fake());

    it('is stored as ignored with its processing already done, and nothing is queued', function () {
        $stored = app(ProviderEventIngestor::class)->ingest($this->conversation, [providerEvent(ProviderEventKind::Ignored, 'k-ignored', payload: ['event_type' => 'application.recording_ready'])]);

        $receipt = receipts()[0];
        expect($stored)->toBe(1)->and($receipt->kind)->toBe(ProviderEventKind::Ignored)->and($receipt->processed_at)->not->toBeNull();
        Queue::assertNothingPushed();
    });
});

describe('the same event twice', function () {
    beforeEach(fn () => Queue::fake());

    it('is stored and queued once', function () {
        $ingestor = app(ProviderEventIngestor::class);

        $first = $ingestor->ingest($this->conversation, [providerEvent()]);
        $second = $ingestor->ingest($this->conversation, [providerEvent()]);

        expect([$first, $second])->toBe([1, 0])->and(receipts())->toHaveCount(1);
        Queue::assertPushed(ProcessProviderEvent::class, 1);
    });

    it('is stored once when a batch repeats it, and the rest of the batch is stored', function () {
        $stored = app(ProviderEventIngestor::class)->ingest($this->conversation, [providerEvent(key: 'a'), providerEvent(key: 'a'), providerEvent(key: 'b')]);

        expect($stored)->toBe(2)->and(receipts())->toHaveCount(2);
        Queue::assertPushed(ProcessProviderEvent::class, 2);
    });

    it('is decided by the unique key, not by a read before it', function () {
        // A copy of the row that landed between a read and the write: the insert is a no-op, not an error.
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        app(ProviderEventIngestor::class)->ingest($this->conversation, [providerEvent()]);
        app(ProviderEventIngestor::class)->ingest($this->conversation, [providerEvent()]);

        expect(collect($queries)->filter(fn (string $sql) => str_starts_with($sql, 'insert into "provider_webhook_receipts"') && str_contains($sql, 'on conflict'))->count())->toBe(2);
    });

    it('is a different event for another provider or another key', function () {
        $ingestor = app(ProviderEventIngestor::class);
        $other = receiptConversation(['provider' => 'scripted']);

        $ingestor->ingest($this->conversation, [providerEvent(key: 'same')]);
        $ingestor->ingest($other, [providerEvent(key: 'same', provider: 'scripted')]);

        expect(receipts())->toHaveCount(2);
    });
});

describe('a stored receipt that was never processed', function () {
    beforeEach(function () {
        Queue::fake();
        Carbon::setTestNow('2026-10-20 12:00:00');
    });

    // The first copy committed, and its job never reached the queue (the push failed) or died: nothing else
    // would ever process it, so the provider's own retry of the event must be able to.
    it('is queued again when the provider sends the event again after the lease', function () {
        $ingestor = app(ProviderEventIngestor::class);
        $ingestor->ingest($this->conversation, [providerEvent()]);
        Carbon::setTestNow(now()->addSeconds(ProviderEventIngestor::STALE_SECONDS + 1));

        $stored = $ingestor->ingest($this->conversation, [providerEvent()]);

        expect($stored)->toBe(0)->and(receipts())->toHaveCount(1);
        Queue::assertPushed(ProcessProviderEvent::class, 2);
        Queue::assertPushed(ProcessProviderEvent::class, fn (ProcessProviderEvent $job) => $job->receiptId === receipts()[0]->id && $job->accountId === $this->account->id);
    });

    it('is not queued twice for copies that arrive together: the first one holds the lease', function () {
        $ingestor = app(ProviderEventIngestor::class);

        $ingestor->ingest($this->conversation, [providerEvent()]);
        $ingestor->ingest($this->conversation, [providerEvent()]);
        $ingestor->ingest($this->conversation, [providerEvent()]);

        Queue::assertPushed(ProcessProviderEvent::class, 1);
    });

    it('is queued once per lease, however many copies come after it', function () {
        $ingestor = app(ProviderEventIngestor::class);
        $ingestor->ingest($this->conversation, [providerEvent()]);
        Carbon::setTestNow(now()->addSeconds(ProviderEventIngestor::STALE_SECONDS + 1));

        $ingestor->ingest($this->conversation, [providerEvent()]);
        $ingestor->ingest($this->conversation, [providerEvent()]);

        Queue::assertPushed(ProcessProviderEvent::class, 2);
    });

    it('is not queued again once it is processed, and an ignored one never is', function () {
        $ingestor = app(ProviderEventIngestor::class);
        $ingestor->ingest($this->conversation, [providerEvent(key: 'done'), providerEvent(ProviderEventKind::Ignored, 'ignored')]);
        DB::table('provider_webhook_receipts')->where('idempotency_key', 'done')->update(['processed_at' => now()]);
        Queue::fake();
        Carbon::setTestNow(now()->addHour());

        $ingestor->ingest($this->conversation, [providerEvent(key: 'done'), providerEvent(ProviderEventKind::Ignored, 'ignored')]);

        Queue::assertNothingPushed();
    });

    it('is queued under its own account, never another\'s receipt of the same key', function () {
        $ingestor = app(ProviderEventIngestor::class);
        $stranger = Account::factory()->create();
        $theirs = receiptConversation(account: $stranger);
        $ingestor->ingest($theirs, [providerEvent(key: 'same')]);
        Carbon::setTestNow(now()->addSeconds(ProviderEventIngestor::STALE_SECONDS + 1));
        Queue::fake();

        $ingestor->ingest($this->conversation, [providerEvent(key: 'same')]);

        // Mine did not exist: it was stored (and queued) once; the stranger's stale one was not touched.
        Queue::assertPushed(ProcessProviderEvent::class, 1);
        Queue::assertPushed(ProcessProviderEvent::class, fn (ProcessProviderEvent $job) => $job->accountId === $this->account->id);
    });
});

describe('the schema', function () {
    beforeEach(fn () => Queue::fake());

    it('keeps (account, provider, key) unique, so another account storing the same key neither blocks nor is blocked', function () {
        $ingestor = app(ProviderEventIngestor::class);
        $stranger = Account::factory()->create();
        $theirs = receiptConversation(account: $stranger);

        $first = $ingestor->ingest($this->conversation, [providerEvent(key: 'same')]);
        $second = $ingestor->ingest($theirs, [providerEvent(key: 'same')]);
        $again = $ingestor->ingest($theirs, [providerEvent(key: 'same')]);

        expect([$first, $second, $again])->toBe([1, 1, 0])
            ->and(array_map(fn ($r) => $r->account_id, receipts()))->toEqualCanonicalizing([$this->account->id, $stranger->id]);
    });

    it('keeps one receipt for a key within an account even across its conversations', function () {
        $ingestor = app(ProviderEventIngestor::class);
        $other = receiptConversation();

        $ingestor->ingest($this->conversation, [providerEvent(key: 'dup')]);
        $stored = $ingestor->ingest($other, [providerEvent(key: 'dup')]);

        expect($stored)->toBe(0)->and(receipts())->toHaveCount(1);
    });

    it('refuses what the table does not allow', function (array $override) {
        $row = [
            'id' => strtolower((string) Str::ulid()), 'account_id' => $this->account->id, 'conversation_id' => $this->conversation->id, 'provider' => 'tavus', 'provider_ref' => 'c123',
            'idempotency_key' => 'k', 'kind' => 'joined', 'occurred_at' => now(), 'payload' => '{}', 'created_at' => now(), 'updated_at' => now(), ...$override,
        ];

        $insert = function () use ($row) {
            DB::transaction(fn () => DB::table('provider_webhook_receipts')->insert($row));
        };

        expect($insert)->toThrow(QueryException::class);
    })->with([
        'a kind that does not exist' => [['kind' => 'exploded']],
        'a payload that is not an object' => [['payload' => '[1,2]']],
        'no account' => [['account_id' => null]],
        'no conversation' => [['conversation_id' => null]],
        'a conversation that does not exist' => [['conversation_id' => 'conv_nope']],
    ]);

    it('goes with its conversation', function () {
        app(ProviderEventIngestor::class)->ingest($this->conversation, [providerEvent()]);

        DB::table('conversations')->where('id', $this->conversation->id)->delete();

        expect(receipts())->toBe([]);
    });

    it('keeps the payload out of every serialization', function () {
        app(ProviderEventIngestor::class)->ingest($this->conversation, [providerEvent(payload: ['event_type' => 'system.pal_joined', 'secret' => 'words'])]);

        $receipt = inAccount($this->account, fn () => ProviderWebhookReceipt::query()->firstOrFail());
        expect($receipt->toArray())->not->toHaveKey('payload')->and($receipt->toJson())->not->toContain('words');
    });

    it('is not mass assignable', function () {
        expect(fn () => ProviderWebhookReceipt::query()->create(['provider' => 'tavus']))->toThrow(Exception::class);
    });

    it('is read only under the account that owns it', function () {
        app(ProviderEventIngestor::class)->ingest($this->conversation, [providerEvent()]);
        $stranger = Account::factory()->create();

        expect(inAccount($stranger, fn () => ProviderWebhookReceipt::query()->count()))->toBe(0)
            ->and(inAccount($this->account, fn () => ProviderWebhookReceipt::query()->count()))->toBe(1);
    });
});

describe('queuing', function () {
    it('queues the processing only once the transaction has committed', function () {
        // The real synchronous queue: a fake records a push the moment it is made, so it cannot show when.
        Http::fake(['*' => Http::response('', 204)]);
        $conversation = receiptConversation();

        DB::transaction(function () use ($conversation) {
            app(ProviderEventIngestor::class)->ingest($conversation, [providerEvent(key: 'after-commit')]);

            // The synchronous queue runs a job the moment it is queued: nothing may have run yet.
            expect(Conversation::withoutGlobalScopes()->find($conversation->id)->status)->toBe(ConversationStatus::Connecting);
        });

        expect(Conversation::withoutGlobalScopes()->find($conversation->id)->status)->toBe(ConversationStatus::Live);
    });

    it('queues nothing and keeps nothing when the transaction rolls back', function () {
        Http::fake(['*' => Http::response('', 204)]);
        $conversation = receiptConversation();

        try {
            DB::transaction(function () use ($conversation) {
                app(ProviderEventIngestor::class)->ingest($conversation, [providerEvent(key: 'rolled-back')]);

                throw new RuntimeException('the surrounding work failed');
            });
        } catch (RuntimeException) {
        }

        // Nothing was stored, and the processing that would have made it live never ran.
        expect(receipts())->toBe([])->and(Conversation::withoutGlobalScopes()->find($conversation->id)->status)->toBe(ConversationStatus::Connecting);
    });
});
