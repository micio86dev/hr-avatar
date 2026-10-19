<?php

namespace App\Jobs;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\ProviderEventKind;
use App\Conversations\ConversationLifecycle;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\EventIngestor;
use App\Conversations\IllegalTransition;
use App\Conversations\TranscriptStatus;
use App\Models\Conversation;
use App\Models\ProviderWebhookReceipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies a stored provider event to its conversation, through the lifecycle, and marks the receipt processed.
 *
 * `joined` makes a connecting conversation live and does nothing in any other state. `ended` ends an open
 * conversation with the reason `provider` and the provider's own reason (`unknown` when it gave none); a
 * conversation that is already over keeps what it has. `transcript_ready` ends a conversation that is still
 * going and queues the reconciliation of the transcript, no earlier than the browser's last events can arrive;
 * the reconciliation reads the receipt's rows where it runs. Events may arrive in any order: each one is
 * absorbed by the state machine, so a repeat or a late one changes nothing.
 *
 * It carries the account id and the receipt id and nothing else, and reads both under that account.
 */
final class ProcessProviderEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $accountId, public readonly string $receiptId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ConversationLifecycle $lifecycle): void
    {
        CurrentAccount::runAs($this->accountId, function () use ($lifecycle): void {
            $receipt = ProviderWebhookReceipt::query()->find($this->receiptId);

            if ($receipt === null || $receipt->processed_at !== null) {
                return;
            }

            $conversation = Conversation::query()->find($receipt->conversation_id);

            if ($conversation !== null) {
                match ($receipt->kind) {
                    ProviderEventKind::Joined => $this->joined($conversation, $lifecycle),
                    ProviderEventKind::Ended => $lifecycle->end($conversation, EndedReason::Provider, $receipt->provider_end_reason ?? 'unknown'),
                    ProviderEventKind::TranscriptReady => $this->transcriptReady($conversation, $lifecycle),
                    ProviderEventKind::Ignored => null,
                };
            }

            ProviderWebhookReceipt::query()->whereKey($receipt->id)->whereNull('processed_at')->update(['processed_at' => now(), 'updated_at' => now()]);
        });
    }

    /**
     * Only the receipt's id and the kind of failure are logged: neither a payload nor a message.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Provider event processing failed', ['receipt_id' => $this->receiptId, 'exception' => $exception::class]);
    }

    private function joined(Conversation $conversation, ConversationLifecycle $lifecycle): void
    {
        if ($conversation->status !== ConversationStatus::Connecting) {
            return;
        }

        try {
            $lifecycle->transition($conversation, ConversationStatus::Live);
        } catch (IllegalTransition) {
            // Ended between the read and the write: a late join changes nothing.
        }
    }

    private function transcriptReady(Conversation $conversation, ConversationLifecycle $lifecycle): void
    {
        $lifecycle->end($conversation, EndedReason::Provider);

        // The conversation is over now, whoever ended it; a transcript that is already finished needs no job.
        if ($conversation->transcript_status !== TranscriptStatus::Pending) {
            return;
        }

        $due = ($conversation->ended_at ?? now())->getTimestamp() + EventIngestor::GRACE_SECONDS;

        ReconcileTranscript::dispatch($this->accountId, $conversation->id, $this->receiptId)->delay(max(0, $due - now()->getTimestamp()));
    }
}
