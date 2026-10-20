<?php

namespace App\Jobs;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\AvatarProvider;
use App\AvatarProviders\ProviderEventKind;
use App\AvatarProviders\ProviderRegistry;
use App\AvatarProviders\TranscriptLine;
use App\AvatarProviders\TranscriptShapeError;
use App\Conversations\ConversationProviderAuth;
use App\Conversations\TranscriptReconciler;
use App\Conversations\TranscriptStatus;
use App\Credentials\CredentialMissing;
use App\Models\Conversation;
use App\Models\ProviderWebhookReceipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches a finished conversation's transcript from its provider and merges it into the stored one.
 *
 * Five attempts, 30 s, 60 s, 120 s and 300 s apart: a transcript that is not ready yet and a provider
 * that fails are retried by letting the exception through. A transcript in a shape that cannot be read
 * will not improve, and neither will a missing credential, so the job fails itself at once in both
 * cases. When the attempts run out (or the job fails itself) the transcript is finished as `partial`,
 * with the lines the browser forwarded.
 *
 * A `transcription_ready` callback already carries the transcript. The job is then given the id of its
 * receipt and reads the rows where it runs, without a provider call or a credential; with no receipt, or
 * none that carries rows, it fetches the provider's copy.
 *
 * It carries the ids and nothing else: the credential is resolved where the job runs, and neither a key
 * nor a transcript line rides on the queue.
 */
final class ReconcileTranscript implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $accountId, public readonly string $conversationId, public readonly ?string $receiptId = null) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(ProviderRegistry $providers, ConversationProviderAuth $auths, TranscriptReconciler $reconciler): void
    {
        CurrentAccount::runAs($this->accountId, function () use ($providers, $auths, $reconciler): void {
            $conversation = Conversation::query()->find($this->conversationId);

            // Finished (or gone, or not this account's): a job run twice has nothing to do.
            if ($conversation === null || $conversation->transcript_status !== TranscriptStatus::Pending) {
                return;
            }

            // A conversation that never reached its provider has no transcript to fetch.
            if ($conversation->provider_session_ref === null) {
                $reconciler->reconcile($conversation, []);

                return;
            }

            $adapter = $providers->get($conversation->provider);

            try {
                $lines = $this->receiptLines($adapter, $conversation);

                if ($lines === []) {
                    $auth = $auths->for($conversation) ?? throw new CredentialMissing;
                    $lines = $adapter->fetchTranscript($conversation->provider_session_ref, $auth);
                }
            } catch (TranscriptShapeError|CredentialMissing $unreadable) {
                $this->fail($unreadable);

                return;
            }

            $reconciler->reconcile($conversation, $lines);
        });
    }

    /**
     * The attempts are used up, or the transcript cannot be read: finish with what the browser forwarded.
     */
    public function failed(Throwable $exception): void
    {
        // Only the kind of failure is logged: a provider's message is not ours to repeat.
        Log::warning('Transcript reconciliation gave up', ['conversation_id' => $this->conversationId, 'exception' => $exception::class]);

        // The failure hook is the last word the queue gives this job: a write that fails here is logged by kind,
        // not thrown. The job is already recorded in `failed_jobs`, so `queue:retry` runs it again.
        try {
            CurrentAccount::runAs($this->accountId, function (): void {
                $conversation = Conversation::query()->find($this->conversationId);

                if ($conversation !== null) {
                    app(TranscriptReconciler::class)->reconcile($conversation, [], TranscriptStatus::Partial);
                }
            });
        } catch (Throwable $unfinished) {
            Log::error('Transcript could not be finished as partial', ['conversation_id' => $this->conversationId, 'exception' => $unfinished::class]);
        }
    }

    /**
     * The lines of the transcript_ready receipt this job was given, when it is the conversation's own and carries any.
     *
     * @return list<TranscriptLine>
     *
     * @throws TranscriptShapeError
     */
    private function receiptLines(AvatarProvider $adapter, Conversation $conversation): array
    {
        if ($this->receiptId === null) {
            return [];
        }

        $receipt = ProviderWebhookReceipt::query()
            ->where('conversation_id', $conversation->id)
            ->where('kind', ProviderEventKind::TranscriptReady->value)
            ->find($this->receiptId);

        return $receipt === null ? [] : $adapter->callbackTranscript($receipt->payload);
    }
}
