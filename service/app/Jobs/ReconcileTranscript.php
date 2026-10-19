<?php

namespace App\Jobs;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ProviderRegistry;
use App\AvatarProviders\TranscriptShapeError;
use App\Conversations\ConversationProviderAuth;
use App\Conversations\TranscriptReconciler;
use App\Conversations\TranscriptStatus;
use App\Models\Conversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches a finished conversation's transcript from its provider and merges it into the stored one.
 *
 * Five attempts, 30 s, 60 s, 120 s and 300 s apart: a transcript that is not ready yet and a provider
 * that fails are retried by letting the exception through. A transcript in a shape that cannot be read
 * will not improve, so the job fails itself at once. When the attempts run out (or the job fails
 * itself) the transcript is finished as `partial`, with the lines the browser forwarded.
 *
 * It carries the account id and the conversation id and nothing else: the credential is resolved where
 * the job runs, and neither a key nor a transcript line rides on the queue.
 */
final class ReconcileTranscript implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $accountId, public readonly string $conversationId) {}

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

            $auth = $auths->for($conversation) ?? throw ProviderException::noResponse();

            try {
                $lines = $providers->get($conversation->provider)->fetchTranscript($conversation->provider_session_ref, $auth);
            } catch (TranscriptShapeError $unreadable) {
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

        CurrentAccount::runAs($this->accountId, function (): void {
            $conversation = Conversation::query()->find($this->conversationId);

            if ($conversation !== null) {
                app(TranscriptReconciler::class)->reconcile($conversation, [], TranscriptStatus::Partial);
            }
        });
    }
}
