<?php

use App\Accounts\CurrentAccount;
use App\AvatarProviders\TranscriptLine;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptReconciler;
use App\Conversations\TranscriptStatus;
use App\Jobs\DeliverWebhook;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->account = Account::factory()->create();
    $config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    $this->conversation = inAccount($this->account, fn () => Conversation::factory()->create([
        'avatar_config_id' => $config->id, 'status' => ConversationStatus::Ended, 'ended_reason' => EndedReason::Api, 'ended_at' => now(), 'provider_session_ref' => 'sess-1',
    ]));
    $this->endpoint = inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
});

function clientLine(string $role, string $text, int $sequence): ConversationEvent
{
    return inAccount(test()->account, fn () => ConversationEvent::forceCreate([
        'conversation_id' => test()->conversation->id, 'sequence' => $sequence, 'source' => 'client', 'type' => 'utterance',
        'role' => $role, 'text' => $text, 'idempotency_key' => "client-{$sequence}", 'occurred_at' => now()->subMinutes(10 - $sequence),
    ]));
}

function providerLine(string $role, string $text, ?string $at = null): TranscriptLine
{
    return new TranscriptLine($role, $text, $at === null ? null : new DateTimeImmutable($at), null);
}

/**
 * @return list<array{0: string, 1: string, 2: string, 3: int}> source, role, text, sequence of the utterances, in sequence order
 */
function utterances(): array
{
    return ConversationEvent::withoutGlobalScopes()->where('conversation_id', test()->conversation->id)->where('type', 'utterance')->orderBy('sequence')->get()
        ->map(fn ($e) => [$e->source, $e->role, $e->text, $e->sequence])->all();
}

function reconcile(array $lines = [], TranscriptStatus $outcome = TranscriptStatus::Ready, ?Conversation $conversation = null): bool
{
    return app(TranscriptReconciler::class)->reconcile($conversation ?? test()->conversation, $lines, $outcome);
}

function transcriptStatus(): TranscriptStatus
{
    return Conversation::withoutGlobalScopes()->find(test()->conversation->id)->transcript_status;
}

/**
 * @return list<WebhookDelivery>
 */
function transcriptDeliveries(): array
{
    return WebhookDelivery::withoutGlobalScopes()->where('event_type', 'transcript.ready')->get()->all();
}

describe('merging', function () {
    it('keeps every client line and adds only what the provider has and the client lacks', function () {
        clientLine('user', 'A', 1);
        clientLine('avatar', 'B', 2);

        $changed = reconcile([providerLine('user', 'A'), providerLine('avatar', 'B'), providerLine('user', 'C')]);

        expect($changed)->toBeTrue()
            ->and(utterances())->toBe([['client', 'user', 'A', 1], ['client', 'avatar', 'B', 2], ['provider', 'user', 'C', 3]])
            ->and(transcriptStatus())->toBe(TranscriptStatus::Ready);
    });

    it('compares role and text after trimming, collapsing whitespace and ignoring case', function (string $client, string $provider) {
        clientLine('user', $client, 1);

        reconcile([providerLine('user', $provider)]);

        expect(utterances())->toBe([['client', 'user', $client, 1]]);
    })->with([
        'collapsed spaces and case' => ['Hello  there', 'hello there'],
        'trimmed' => ['  Hello there ', 'Hello there'],
        'tabs and newlines' => ["Hello\t\nthere", 'Hello there'],
        'unicode case' => ['Èccolo qui', 'èCCOLO QUI'],
        'identical' => ['Same.', 'Same.'],
    ]);

    it('does not treat different text or the other role as the same line', function (string $role, string $text) {
        clientLine('user', 'Hello there', 1);

        reconcile([providerLine($role, $text)]);

        expect(utterances())->toHaveCount(2);
    })->with([
        'the same words from the avatar' => ['avatar', 'Hello there'],
        'other words' => ['user', 'Hello there!'],
        'other spacing inside a word' => ['user', 'Hello the re'],
    ]);

    it('lets a client line account for one provider line only: a repeated line is not lost', function () {
        clientLine('user', 'Yes', 1);

        reconcile([providerLine('user', 'Yes'), providerLine('user', 'yes'), providerLine('user', 'YES ')]);

        expect(utterances())->toBe([['client', 'user', 'Yes', 1], ['provider', 'user', 'yes', 2], ['provider', 'user', 'YES ', 3]]);
    });

    it('adds every line when the client forwarded none', function () {
        reconcile([providerLine('avatar', 'Hi'), providerLine('user', 'Hello'), providerLine('avatar', 'Hi')]);

        expect(utterances())->toBe([['provider', 'avatar', 'Hi', 1], ['provider', 'user', 'Hello', 2], ['provider', 'avatar', 'Hi', 3]]);
    });

    it('continues the sequence after the highest one stored, lifecycle events included, in the provider\'s order', function () {
        clientLine('user', 'A', 1);
        inAccount($this->account, fn () => ConversationEvent::forceCreate([
            'conversation_id' => $this->conversation->id, 'sequence' => 7, 'source' => 'client', 'type' => 'lifecycle', 'name' => 'live', 'idempotency_key' => 'life-1',
        ]));

        reconcile([providerLine('avatar', 'X'), providerLine('user', 'Y')]);

        expect(utterances())->toBe([['client', 'user', 'A', 1], ['provider', 'avatar', 'X', 8], ['provider', 'user', 'Y', 9]]);
    });

    it('does not match a line against a lifecycle event', function () {
        inAccount($this->account, fn () => ConversationEvent::forceCreate([
            'conversation_id' => $this->conversation->id, 'sequence' => 1, 'source' => 'client', 'type' => 'lifecycle', 'name' => 'error', 'text' => 'Hello', 'role' => 'user', 'idempotency_key' => 'life-1',
        ]));

        reconcile([providerLine('user', 'Hello')]);

        expect(utterances())->toBe([['provider', 'user', 'Hello', 2]]);
    });

    it('stores what the provider said: the text as given, the time when it has one, a key per line', function () {
        reconcile([providerLine('avatar', "  Ciao,  mondo \n", '2026-10-19T10:00:00Z'), providerLine('user', 'No time')]);

        $events = ConversationEvent::withoutGlobalScopes()->where('conversation_id', $this->conversation->id)->orderBy('sequence')->get();
        expect($events[0]->text)->toBe("  Ciao,  mondo \n")
            ->and($events[0]->occurred_at->equalTo(Carbon::parse('2026-10-19T10:00:00Z')))->toBeTrue()
            ->and($events[1]->occurred_at)->toBeNull()
            ->and($events->pluck('idempotency_key')->all())->toBe(['provider:0', 'provider:1'])
            ->and($events->pluck('source')->unique()->all())->toBe(['provider'])
            ->and($events->pluck('account_id')->unique()->all())->toBe([$this->account->id]);
    });
});

describe('finishing', function () {
    it('finalizes a conversation with no provider lines as an empty ready transcript', function () {
        expect(reconcile())->toBeTrue();

        expect(utterances())->toBe([])->and(transcriptStatus())->toBe(TranscriptStatus::Ready);
    });

    it('finalizes as partial when asked to, keeping the client lines', function () {
        clientLine('user', 'A', 1);

        expect(reconcile([], TranscriptStatus::Partial))->toBeTrue();

        expect(utterances())->toBe([['client', 'user', 'A', 1]])->and(transcriptStatus())->toBe(TranscriptStatus::Partial);
    });

    it('refuses to finish anything but ready or partial', function () {
        expect(fn () => reconcile([], TranscriptStatus::Pending))->toThrow(InvalidArgumentException::class);
    });

    it('does nothing for a conversation that has not ended', function (ConversationStatus $status) {
        $this->conversation->forceFill(['status' => $status])->save();

        expect(reconcile([providerLine('user', 'Hi')]))->toBeFalse()
            ->and(utterances())->toBe([])->and(transcriptStatus())->toBe(TranscriptStatus::Pending);
        Queue::assertNothingPushed();
    })->with([ConversationStatus::Created, ConversationStatus::Connecting, ConversationStatus::Live]);

    it('finishes a conversation that failed, as it does one that ended', function () {
        $this->conversation->forceFill(['status' => ConversationStatus::Failed, 'ended_reason' => EndedReason::ProviderError])->save();

        expect(reconcile())->toBeTrue()->and(transcriptStatus())->toBe(TranscriptStatus::Ready);
    });
});

describe('once', function () {
    it('changes nothing the second time, and says so', function () {
        clientLine('user', 'A', 1);
        reconcile([providerLine('user', 'A'), providerLine('avatar', 'B')]);

        $again = reconcile([providerLine('user', 'A'), providerLine('avatar', 'B'), providerLine('avatar', 'C')]);

        expect($again)->toBeFalse()->and(utterances())->toBe([['client', 'user', 'A', 1], ['provider', 'avatar', 'B', 2]]);
    });

    it('does not let a later partial run override a ready transcript, or the other way round', function (TranscriptStatus $first, TranscriptStatus $second) {
        reconcile([], $first);

        expect(reconcile([providerLine('user', 'Late')], $second))->toBeFalse()->and(transcriptStatus())->toBe($first)->and(utterances())->toBe([]);
    })->with([[TranscriptStatus::Ready, TranscriptStatus::Partial], [TranscriptStatus::Partial, TranscriptStatus::Ready]]);

    it('works from a stale copy of the conversation: the database decides', function () {
        $stale = Conversation::withoutGlobalScopes()->find($this->conversation->id);
        reconcile([providerLine('user', 'A')]);

        expect(reconcile([providerLine('user', 'B')], TranscriptStatus::Ready, $stale))->toBeFalse()->and(utterances())->toHaveCount(1);
    });
});

describe('transcript.ready', function () {
    it('is recorded once, with the final status and the number of utterances, and no text', function () {
        clientLine('user', 'A secret sentence', 1);

        reconcile([providerLine('user', 'A secret sentence'), providerLine('avatar', 'Another one')]);
        reconcile([providerLine('avatar', 'Third')]);

        $deliveries = transcriptDeliveries();
        $body = json_decode($deliveries[0]->payload, true);
        expect($deliveries)->toHaveCount(1)
            ->and($body['type'])->toBe('transcript.ready')
            ->and($body['data']['transcript'])->toBe(['status' => 'ready', 'utterance_count' => 2])
            ->and($body['data']['conversation']['transcript_status'])->toBe('ready')
            ->and($deliveries[0]->payload)->not->toContain('secret sentence')->not->toContain('Another one');
        Queue::assertPushed(DeliverWebhook::class, 1);
    });

    it('counts utterances only, from both sides', function () {
        clientLine('user', 'A', 1);
        inAccount($this->account, fn () => ConversationEvent::forceCreate([
            'conversation_id' => $this->conversation->id, 'sequence' => 2, 'source' => 'client', 'type' => 'lifecycle', 'name' => 'live', 'idempotency_key' => 'life-1',
        ]));

        reconcile([providerLine('avatar', 'B'), providerLine('user', 'C')]);

        expect(json_decode(transcriptDeliveries()[0]->payload, true)['data']['transcript']['utterance_count'])->toBe(3);
    });

    it('says partial when the transcript is partial', function () {
        clientLine('user', 'A', 1);

        reconcile([], TranscriptStatus::Partial);

        expect(json_decode(transcriptDeliveries()[0]->payload, true)['data']['transcript'])->toBe(['status' => 'partial', 'utterance_count' => 1]);
    });

    it('is emitted for an empty transcript too', function () {
        reconcile();

        expect(json_decode(transcriptDeliveries()[0]->payload, true)['data']['transcript'])->toBe(['status' => 'ready', 'utterance_count' => 0]);
    });

    it('is not emitted for a no-op', function () {
        $this->conversation->forceFill(['status' => ConversationStatus::Live])->save();

        reconcile();

        expect(transcriptDeliveries())->toBe([]);
    });

    it('goes to the endpoints of the conversation\'s account that subscribe to it, and to no one else', function () {
        inAccount($this->account, fn () => WebhookEndpoint::factory()->create(['events' => ['conversation.ended']]));
        inAccount(Account::factory()->create(), fn () => WebhookEndpoint::factory()->create());

        reconcile();

        expect(array_column(transcriptDeliveries(), 'webhook_endpoint_id'))->toBe([$this->endpoint->id]);
    });
});

describe('the transaction', function () {
    it('is one: if the caller\'s transaction fails afterwards, the lines, the status and the delivery all go', function () {
        clientLine('user', 'A', 1);

        try {
            DB::transaction(function () {
                reconcile([providerLine('avatar', 'B')]);

                throw new RuntimeException('the surrounding work failed');
            });
        } catch (RuntimeException) {
        }

        expect(utterances())->toBe([['client', 'user', 'A', 1]])->and(transcriptStatus())->toBe(TranscriptStatus::Pending)->and(transcriptDeliveries())->toBe([]);
    });

    it('rolls its own work back when recording the event fails', function () {
        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT never CHECK (event_type <> 'transcript.ready')");

        try {
            reconcile([providerLine('avatar', 'B')]);
            $this->fail('The event could not be recorded, yet nothing failed.');
        } catch (QueryException) {
        }

        expect(utterances())->toBe([])->and(transcriptStatus())->toBe(TranscriptStatus::Pending);
    });

    it('takes the conversation row lock before it counts and numbers, so client events cannot slip in between', function () {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        reconcile([providerLine('avatar', 'B')]);

        $update = array_find_key($queries, fn (string $sql) => str_starts_with($sql, 'update "conversations"') && str_contains($sql, 'transcript_status'));
        $max = array_find_key($queries, fn (string $sql) => str_contains($sql, 'max("sequence")') || str_contains($sql, 'max(sequence)'));
        $insert = array_find_key($queries, fn (string $sql) => str_contains($sql, 'insert into "conversation_events"'));
        expect($update)->not->toBeNull()->and($max)->not->toBeNull()->and($insert)->not->toBeNull()->and($update)->toBeLessThan($max)->and($max)->toBeLessThan($insert);
    });
});

describe('the account', function () {
    it('is bound by the reconciler itself and left as it was found', function () {
        expect(app(CurrentAccount::class)->id())->toBeNull();

        reconcile([providerLine('user', 'A')]);

        expect(utterances())->toHaveCount(1)->and(app(CurrentAccount::class)->id())->toBeNull();
    });
});
