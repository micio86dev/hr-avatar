<?php

use App\AvatarProviders\ProviderEvent;
use App\AvatarProviders\ProviderEventKind;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\ProviderEventIngestor;
use App\Conversations\TranscriptStatus;
use App\Jobs\ReconcileTranscript;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ProviderCredential;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * A transcription_ready callback carries the transcript. The job is handed the receipt's id (never the
 * rows, which would put transcript text on the queue and in the failed-job record) and reads the rows
 * where it runs. Without a usable receipt it fetches the provider's copy as before.
 */
beforeEach(function () {
    Queue::fake();
    $this->account = Account::factory()->create();
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
    inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'tv-key-123456']));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function receiptEnded(array $attributes = [], ?Account $account = null): Conversation
{
    $account ??= test()->account;
    $config = inAccount($account, fn () => AvatarConfig::factory()->create());

    return inAccount($account, fn () => Conversation::factory()->create([
        'avatar_config_id' => $config->id, 'provider' => 'tavus', 'status' => ConversationStatus::Ended, 'ended_reason' => EndedReason::Client,
        'ended_at' => now()->subMinutes(2), 'provider_session_ref' => 'c123', ...$attributes,
    ]));
}

/**
 * Stores a receipt of the given kind carrying the given transcript rows; returns its id.
 *
 * @param  array<array-key, mixed>|null  $rows
 */
function receiptWithRows(Conversation $conversation, ?array $rows, ProviderEventKind $kind = ProviderEventKind::TranscriptReady, string $key = 'receipt-rows'): string
{
    app(ProviderEventIngestor::class)->ingest($conversation, [new ProviderEvent(
        'tavus', 'c123', $kind, now()->toDateTimeImmutable(), $key, null,
        ['conversation_id' => 'c123', 'event_type' => 'application.transcription_ready', ...($rows === null ? [] : ['properties' => ['transcript' => $rows]])],
    )]);

    return DB::table('provider_webhook_receipts')->where('idempotency_key', $key)->value('id');
}

function runReceiptReconcile(Conversation $conversation, ?string $receiptId, ?string $accountId = null): void
{
    app()->call([new ReconcileTranscript($accountId ?? $conversation->account_id, $conversation->id, $receiptId), 'handle']);
}

/**
 * @return list<array{0: string, 1: string, 2: string}>
 */
function receiptLines(Conversation $conversation): array
{
    return ConversationEvent::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('sequence')->get()->map(fn ($e) => [$e->source, $e->role, $e->text])->all();
}

function receiptStatus(Conversation $conversation): TranscriptStatus
{
    return Conversation::withoutGlobalScopes()->findOrFail($conversation->id)->transcript_status;
}

describe('with the rows of a receipt', function () {
    it('merges them without asking the provider for anything', function () {
        $conversation = receiptEnded();
        inAccount($this->account, fn () => ConversationEvent::forceCreate(['conversation_id' => $conversation->id, 'sequence' => 1, 'source' => 'client', 'type' => 'utterance', 'role' => 'user', 'text' => 'Hi', 'idempotency_key' => 'c1']));
        $receipt = receiptWithRows($conversation, [['role' => 'system', 'content' => 'prompt'], ['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Welcome.']]);

        runReceiptReconcile($conversation, $receipt);

        expect(receiptLines($conversation))->toBe([['client', 'user', 'Hi'], ['provider', 'avatar', 'Welcome.']])
            ->and(receiptStatus($conversation))->toBe(TranscriptStatus::Ready);
        Http::assertNothingSent();
    });

    it('needs no credential at all', function () {
        DB::table('provider_credentials')->delete();
        config()->set('hr-avatar.platform_keys.tavus', null);
        $conversation = receiptEnded();
        $receipt = receiptWithRows($conversation, [['role' => 'user', 'content' => 'Hi']]);

        runReceiptReconcile($conversation, $receipt);

        expect(receiptLines($conversation))->toBe([['provider', 'user', 'Hi']]);
        Http::assertNothingSent();
    });

    it('does nothing for a transcript that is already finished', function () {
        $conversation = receiptEnded(['transcript_status' => TranscriptStatus::Ready]);
        $receipt = receiptWithRows($conversation, [['role' => 'user', 'content' => 'Hi']]);

        runReceiptReconcile($conversation, $receipt);

        expect(receiptLines($conversation))->toBe([]);
    });
});

describe('without a usable receipt', function () {
    $fetched = fn () => Http::fake(['tavusapi.com/*' => Http::response(['status' => 'ended', 'events' => [['event_type' => 'application.transcription_ready', 'properties' => ['transcript' => [['role' => 'assistant', 'content' => 'From the provider']]]]]])]);

    it('fetches the provider\'s copy when the receipt carries no rows', function (?array $rows) use ($fetched) {
        $fetched();
        $conversation = receiptEnded();
        $receipt = receiptWithRows($conversation, $rows);

        runReceiptReconcile($conversation, $receipt);

        expect(receiptLines($conversation))->toBe([['provider', 'avatar', 'From the provider']]);
        Http::assertSentCount(1);
    })->with(['no transcript at all' => [null], 'an empty transcript' => [[]]]);

    it('fetches the provider\'s copy when there is no receipt id, or the receipt is not there', function (?string $receipt) use ($fetched) {
        $fetched();
        $conversation = receiptEnded();

        runReceiptReconcile($conversation, $receipt);

        expect(receiptLines($conversation))->toBe([['provider', 'avatar', 'From the provider']]);
    })->with(['none' => [null], 'a missing one' => ['01jnotthere00000000000000']]);

    it('fetches the provider\'s copy when the receipt is for another conversation, another account or another kind', function (string $case) use ($fetched) {
        $fetched();
        $conversation = receiptEnded();
        $other = receiptEnded();
        $receipt = match ($case) {
            'another conversation' => receiptWithRows($other, [['role' => 'user', 'content' => 'Not this conversation']]),
            'another kind' => receiptWithRows($conversation, [['role' => 'user', 'content' => 'Not a transcript event']], ProviderEventKind::Ended),
            'another account' => (function () {
                $stranger = Account::factory()->create();
                $theirs = receiptEnded(account: $stranger);

                return receiptWithRows($theirs, [['role' => 'user', 'content' => 'Not this account']]);
            })(),
        };

        runReceiptReconcile($conversation, $receipt);

        expect(receiptLines($conversation))->toBe([['provider', 'avatar', 'From the provider']]);
    })->with(['another conversation', 'another kind', 'another account']);
});

describe('rows that cannot be read', function () {
    it('fail the job at once, as an unreadable fetched transcript does', function () {
        $conversation = receiptEnded();
        $receipt = receiptWithRows($conversation, [['role' => 'narrator', 'content' => 'Hi']]);
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('fail')->once();
        $job = new ReconcileTranscript($conversation->account_id, $conversation->id, $receipt);
        $job->setJob($queueJob);

        app()->call([$job, 'handle']);

        expect(receiptStatus($conversation))->toBe(TranscriptStatus::Pending);
        Http::assertNothingSent();
    });
});

describe('the job', function () {
    it('carries the ids and no transcript text', function () {
        $conversation = receiptEnded();
        $receipt = receiptWithRows($conversation, [['role' => 'user', 'content' => 'private words']]);

        $serialized = serialize(new ReconcileTranscript($conversation->account_id, $conversation->id, $receipt));

        expect($serialized)->toContain($receipt)->and($serialized)->not->toContain('private words');
    });

    it('is still built with the two ids it always took', function () {
        $job = new ReconcileTranscript('acc', 'conv');

        expect($job->receiptId)->toBeNull();
    });
});
