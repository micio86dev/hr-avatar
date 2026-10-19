<?php

use App\AvatarProviders\ProviderEvent;
use App\AvatarProviders\ProviderEventKind;
use App\AvatarProviders\ProviderRegistry;
use App\Conversations\ConversationLifecycle;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\ProviderEventIngestor;
use App\Conversations\TranscriptStatus;
use App\Jobs\ProcessProviderEvent;
use App\Jobs\ReconcileTranscript;
use App\Jobs\ReleaseProviderSession;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ProviderWebhookReceipt;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 12:00:00');
    $this->account = Account::factory()->create();
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
});

/**
 * A Tavus conversation in the given state.
 *
 * @param  array<string, mixed>  $attributes
 */
function ppConversation(ConversationStatus $status = ConversationStatus::Connecting, array $attributes = [], ?Account $account = null): Conversation
{
    $account ??= test()->account;
    $config = $account->is(test()->account) ? test()->config : inAccount($account, fn () => AvatarConfig::factory()->create());
    $implied = match ($status) {
        ConversationStatus::Created => [],
        ConversationStatus::Connecting => ['connecting_at' => now()->subMinute(), 'provider_session_ref' => 'c123'],
        ConversationStatus::Live => ['connecting_at' => now()->subMinutes(2), 'live_at' => now()->subMinute(), 'provider_session_ref' => 'c123'],
        ConversationStatus::Ended => ['connecting_at' => now()->subMinutes(5), 'ended_at' => now()->subMinutes(2), 'ended_reason' => EndedReason::Client, 'provider_session_ref' => 'c123'],
        ConversationStatus::Failed => ['connecting_at' => now()->subMinutes(5), 'ended_at' => now()->subMinutes(2), 'ended_reason' => EndedReason::ProviderError],
    };

    return inAccount($account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id, 'provider' => 'tavus', 'status' => $status, ...$implied, ...$attributes]));
}

/**
 * What Tavus posts, parsed by the real adapter and stored by the real ingestor; returns the receipt ids in order.
 *
 * @param  array<string, mixed>  $payload
 * @return list<string>
 */
function ppReceive(Conversation $conversation, array $payload): array
{
    $events = app(ProviderRegistry::class)->get('tavus')->parseCallback(['conversation_id' => 'c123', 'timestamp' => '2026-10-19T12:00:00Z', ...$payload]);
    $before = ProviderWebhookReceipt::withoutGlobalScopes()->pluck('id')->all();
    app(ProviderEventIngestor::class)->ingest($conversation, $events);

    return array_values(array_diff(ProviderWebhookReceipt::withoutGlobalScopes()->orderBy('created_at')->orderBy('id')->pluck('id')->all(), $before));
}

function ppProcess(string $receiptId, ?string $accountId = null): void
{
    app()->call([new ProcessProviderEvent($accountId ?? test()->account->id, $receiptId), 'handle']);
}

function ppFresh(Conversation $conversation): Conversation
{
    return Conversation::withoutGlobalScopes()->findOrFail($conversation->id);
}

/**
 * @return list<string>
 */
function ppEvents(Conversation $conversation): array
{
    return WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('created_at')->orderBy('id')->get()
        ->map(fn (WebhookDelivery $delivery) => $delivery->event_type->value)->all();
}

function ppReceipt(string $id): ProviderWebhookReceipt
{
    return ProviderWebhookReceipt::withoutGlobalScopes()->findOrFail($id);
}

describe('a joined event', function () {
    beforeEach(fn () => Queue::fake());

    it('makes a connecting conversation live and emits conversation.live', function (string $type) {
        $conversation = ppConversation();
        [$receipt] = ppReceive($conversation, ['event_type' => $type]);

        ppProcess($receipt);

        expect(ppFresh($conversation)->status)->toBe(ConversationStatus::Live)
            ->and(ppFresh($conversation)->live_at?->toIso8601String())->toBe(now()->toIso8601String())
            ->and(ppEvents($conversation))->toBe(['conversation.live'])
            ->and(ppReceipt($receipt)->processed_at)->not->toBeNull();
    })->with(['system.pal_joined', 'system.replica_joined']);

    it('makes the conversation live once when both spellings arrive', function () {
        $conversation = ppConversation();
        $receipts = [...ppReceive($conversation, ['event_type' => 'system.pal_joined']), ...ppReceive($conversation, ['event_type' => 'system.replica_joined'])];

        array_map(ppProcess(...), $receipts);

        expect($receipts)->toHaveCount(2)->and(ppFresh($conversation)->status)->toBe(ConversationStatus::Live)->and(ppEvents($conversation))->toBe(['conversation.live']);
    });

    it('changes nothing in any other state', function (ConversationStatus $status) {
        $conversation = ppConversation($status);
        $before = ppFresh($conversation)->getAttributes();
        [$receipt] = ppReceive($conversation, ['event_type' => 'system.pal_joined']);

        ppProcess($receipt);

        expect(ppFresh($conversation)->getAttributes())->toBe($before)
            ->and(ppEvents($conversation))->toBe([])
            ->and(ppReceipt($receipt)->processed_at)->not->toBeNull();
    })->with([ConversationStatus::Created, ConversationStatus::Live, ConversationStatus::Ended, ConversationStatus::Failed]);
});

describe('a shutdown event', function () {
    beforeEach(fn () => Queue::fake());

    it('ends the conversation with the reason provider and the reason Tavus gave', function (array $properties, string $expected) {
        $conversation = ppConversation(ConversationStatus::Live);
        [$receipt] = ppReceive($conversation, ['event_type' => 'system.shutdown', 'properties' => $properties]);

        ppProcess($receipt);

        $row = ppFresh($conversation);
        expect($row->status)->toBe(ConversationStatus::Ended)
            ->and($row->ended_reason)->toBe(EndedReason::Provider)
            ->and($row->provider_end_reason)->toBe($expected)
            ->and(ppEvents($conversation))->toBe(['conversation.ended'])
            ->and(ppReceipt($receipt)->processed_at)->not->toBeNull();
        Queue::assertPushed(ReleaseProviderSession::class, fn (ReleaseProviderSession $job) => $job->conversationId === $conversation->id);
    })->with([
        'the primary key' => [['shutdown_reason' => 'participant_left'], 'participant_left'],
        'the alternate key' => [['reason' => 'max_call_duration_reached'], 'max_call_duration_reached'],
        'both, the primary wins' => [['shutdown_reason' => 'participant_left', 'reason' => 'other'], 'participant_left'],
        'neither' => [[], 'unknown'],
    ]);

    it('ends a conversation that is still connecting', function () {
        $conversation = ppConversation();
        [$receipt] = ppReceive($conversation, ['event_type' => 'system.shutdown']);

        ppProcess($receipt);

        expect(ppFresh($conversation)->status)->toBe(ConversationStatus::Ended);
    });

    it('lets whoever ended the conversation first keep the reason, and emits nothing more', function () {
        $conversation = ppConversation(ConversationStatus::Ended, ['ended_reason' => EndedReason::Api]);
        [$receipt] = ppReceive($conversation, ['event_type' => 'system.shutdown', 'properties' => ['shutdown_reason' => 'participant_left']]);

        ppProcess($receipt);

        $row = ppFresh($conversation);
        expect($row->ended_reason)->toBe(EndedReason::Api)->and($row->provider_end_reason)->toBeNull()->and(ppEvents($conversation))->toBe([]);
        Queue::assertNotPushed(ReleaseProviderSession::class);
        Queue::assertNotPushed(ReconcileTranscript::class);
    });

    it('does not end a failed conversation', function () {
        $conversation = ppConversation(ConversationStatus::Failed);
        [$receipt] = ppReceive($conversation, ['event_type' => 'system.shutdown']);

        ppProcess($receipt);

        expect(ppFresh($conversation)->status)->toBe(ConversationStatus::Failed);
    });

    it('falls back to unknown when the receipt holds no reason', function () {
        $conversation = ppConversation(ConversationStatus::Live);
        $ingestor = app(ProviderEventIngestor::class);
        $ingestor->ingest($conversation, [new ProviderEvent('tavus', 'c123', ProviderEventKind::Ended, now()->toDateTimeImmutable(), 'no-reason', null, [])]);
        $receipt = ProviderWebhookReceipt::withoutGlobalScopes()->where('idempotency_key', 'no-reason')->firstOrFail()->id;

        ppProcess($receipt);

        expect(ppFresh($conversation)->provider_end_reason)->toBe('unknown');
    });
});

describe('a transcription_ready event', function () {
    $rows = [['role' => 'system', 'content' => 'prompt'], ['role' => 'assistant', 'content' => 'Hello!'], ['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Welcome.']];

    it('queues the reconciliation with the receipt\'s id, never its rows, no earlier than a minute after the end', function () use ($rows) {
        Queue::fake();
        $conversation = ppConversation(ConversationStatus::Ended, ['ended_at' => now()->subSeconds(10)]);
        [$receipt] = ppReceive($conversation, ['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => $rows]]);

        ppProcess($receipt);

        Queue::assertPushed(ReconcileTranscript::class, fn (ReconcileTranscript $job) => $job->accountId === $conversation->account_id
            && $job->conversationId === $conversation->id
            && $job->receiptId === $receipt
            && $job->delay === 50);
        Queue::assertPushed(ReconcileTranscript::class, 1);
        expect(ppReceipt($receipt)->processed_at)->not->toBeNull();
    });

    it('does not wait when the end was more than a minute ago', function () use ($rows) {
        Queue::fake();
        $conversation = ppConversation(ConversationStatus::Ended);
        [$receipt] = ppReceive($conversation, ['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => $rows]]);

        ppProcess($receipt);

        Queue::assertPushed(ReconcileTranscript::class, fn (ReconcileTranscript $job) => $job->delay === 0);
    });

    it('ends a conversation that is still going first, with the reason provider, and then reconciles', function () use ($rows) {
        Queue::fake();
        $conversation = ppConversation(ConversationStatus::Live);
        [$receipt] = ppReceive($conversation, ['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => $rows]]);

        ppProcess($receipt);

        $row = ppFresh($conversation);
        expect($row->status)->toBe(ConversationStatus::Ended)->and($row->ended_reason)->toBe(EndedReason::Provider)->and($row->provider_end_reason)->toBeNull()
            ->and(ppEvents($conversation))->toBe(['conversation.ended']);
        Queue::assertPushed(ReleaseProviderSession::class, 1);
        Queue::assertPushed(ReconcileTranscript::class, fn (ReconcileTranscript $job) => $job->receiptId === $receipt && $job->delay === 60);
    });

    it('merges what the callback carried into the transcript, once, and finishes it', function () use ($rows) {
        Http::fake(['*' => Http::response('', 204)]);
        $conversation = ppConversation(ConversationStatus::Ended);
        foreach ([[1, 'user', 'Hi'], [2, 'avatar', 'Hello!']] as [$sequence, $role, $text]) {
            inAccount($this->account, fn () => ConversationEvent::forceCreate([
                'conversation_id' => $conversation->id, 'sequence' => $sequence, 'source' => 'client', 'type' => 'utterance', 'role' => $role, 'text' => $text, 'idempotency_key' => "c{$sequence}",
            ]));
        }
        [$receipt] = ppReceive($conversation, ['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => $rows]]);

        ppProcess($receipt);

        $lines = ConversationEvent::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('sequence')->get()->map(fn ($e) => [$e->source, $e->role, $e->text])->all();
        expect(ppFresh($conversation)->transcript_status)->toBe(TranscriptStatus::Ready)
            ->and($lines)->toBe([['client', 'user', 'Hi'], ['client', 'avatar', 'Hello!'], ['provider', 'avatar', 'Welcome.']])
            ->and(ppEvents($conversation))->toBe(['transcript.ready']);
        Http::assertSentCount(1);
    });
});

describe('an event that was already processed, or is not for this job', function () {
    beforeEach(fn () => Queue::fake());

    it('is processed once however many times the job runs', function () {
        $conversation = ppConversation(ConversationStatus::Live);
        [$receipt] = ppReceive($conversation, ['event_type' => 'system.shutdown']);
        ppProcess($receipt);
        $processedAt = ppReceipt($receipt)->processed_at;
        $this->travel(5)->seconds();

        ppProcess($receipt);

        expect(ppReceipt($receipt)->processed_at?->equalTo($processedAt))->toBeTrue()->and(ppEvents($conversation))->toBe(['conversation.ended']);
        Queue::assertPushed(ReleaseProviderSession::class, 1);
    });

    it('queues the reconciliation once however many times the job runs', function () {
        $conversation = ppConversation(ConversationStatus::Ended);
        [$receipt] = ppReceive($conversation, ['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => [['role' => 'user', 'content' => 'Hi']]]]);

        ppProcess($receipt);
        ppProcess($receipt);

        Queue::assertPushed(ReconcileTranscript::class, 1);
    });

    it('does nothing for a receipt of another account, or one that does not exist', function () {
        $conversation = ppConversation();
        [$receipt] = ppReceive($conversation, ['event_type' => 'system.pal_joined']);
        $stranger = Account::factory()->create();

        ppProcess($receipt, $stranger->id);
        ppProcess('01jnotthere00000000000000');

        expect(ppFresh($conversation)->status)->toBe(ConversationStatus::Connecting)->and(ppReceipt($receipt)->processed_at)->toBeNull();
    });

    it('does nothing and marks done an ignored receipt that was left unprocessed', function () {
        $conversation = ppConversation();
        [$receipt] = ppReceive($conversation, ['event_type' => 'application.recording_ready']);
        DB::table('provider_webhook_receipts')->where('id', $receipt)->update(['processed_at' => null]);
        $before = ppFresh($conversation)->getAttributes();

        ppProcess($receipt);

        expect(ppFresh($conversation)->getAttributes())->toBe($before)->and(ppReceipt($receipt)->processed_at)->not->toBeNull();
    });

    it('lets a conversation that ended while the event was being applied stay ended', function () {
        $conversation = ppConversation();
        [$receipt] = ppReceive($conversation, ['event_type' => 'system.pal_joined']);
        $ended = false;
        Conversation::retrieved(function (Conversation $model) use (&$ended, $conversation) {
            if ($ended || $model->id !== $conversation->id) {
                return;
            }

            // Another process ends it between this job's read and its write.
            $ended = true;
            app(ConversationLifecycle::class)->end(Conversation::withoutGlobalScopes()->findOrFail($conversation->id), EndedReason::Api);
        });

        ppProcess($receipt);

        expect(ppFresh($conversation)->status)->toBe(ConversationStatus::Ended)->and(ppEvents($conversation))->toBe(['conversation.ended'])->and(ppReceipt($receipt)->processed_at)->not->toBeNull();
    });

    it('queues no reconciliation for a transcript that is already finished', function () {
        $conversation = ppConversation(ConversationStatus::Ended, ['transcript_status' => TranscriptStatus::Ready]);
        [$receipt] = ppReceive($conversation, ['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => [['role' => 'user', 'content' => 'Hi']]]]);

        ppProcess($receipt);

        Queue::assertNotPushed(ReconcileTranscript::class);
    });

    it('does nothing for an event that is stored as ignored', function () {
        $conversation = ppConversation();
        [$receipt] = ppReceive($conversation, ['event_type' => 'application.recording_ready']);
        $before = ppFresh($conversation)->getAttributes();

        ppProcess($receipt);

        expect(ppFresh($conversation)->getAttributes())->toBe($before)->and(ppReceipt($receipt)->kind)->toBe(ProviderEventKind::Ignored);
    });
});

describe('the job', function () {
    beforeEach(fn () => Queue::fake());

    it('carries the account and the receipt id and nothing else', function () {
        $conversation = ppConversation();
        [$receipt] = ppReceive($conversation, ['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => [['role' => 'user', 'content' => 'private words']]]]);

        $serialized = serialize(new ProcessProviderEvent($this->account->id, $receipt));

        expect($serialized)->not->toContain('private words')->and($serialized)->toContain($receipt)->and($serialized)->not->toContain('c123');
    });

    it('is retried a few times, and a failure is logged by id and kind only', function () {
        $logs = captureLogs();
        $job = new ProcessProviderEvent($this->account->id, 'receipt-1');

        $job->failed(new RuntimeException('a message with a secret'));

        expect($job->tries)->toBe(3)->and($job->backoff())->toBe([10, 60])
            ->and(collect($logs)->contains(fn (string $line) => str_contains($line, 'receipt-1')))->toBeTrue()
            ->and(collect($logs)->contains(fn (string $line) => str_contains($line, 'secret')))->toBeFalse();
    });
});
