<?php

namespace App\Jobs;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\ProviderRegistry;
use App\Conversations\ConversationProviderAuth;
use App\Models\Conversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Releases what a finished conversation holds at its provider, a little after it ended, and queues
 * the reconciliation of its transcript.
 *
 * Five attempts: a teardown that reports it did not succeed is released back to the queue after 10 s,
 * 30 s, 60 s and 120 s, and after the fifth it is only logged. Nothing here changes the conversation but
 * the `released_at` stamp, whatever the provider says; the job does not depend on any provider webhook
 * having arrived, and a stamped conversation is not released twice.
 *
 * The transcript job is queued once, by the first attempt that gets to it (a mark in the cache says it was),
 * to run no earlier than 60 seconds after the end (the browser may still be sending its last events until
 * then), so reconciliation never waits on teardown.
 *
 * It carries the account id and the conversation id and nothing else: the provider references and the
 * credential are read where it runs.
 */
final class ReleaseProviderSession implements ShouldQueue
{
    use Queueable;

    /** How long after the end the browser may still send events, and so how soon the transcript may be reconciled. */
    private const EVENTS_GRACE_SECONDS = 60;

    /** How long the mark that the transcript job was queued is kept: longer than any retry of this job. */
    private const QUEUED_MARK_SECONDS = 86400;

    public int $tries = 5;

    public function __construct(public readonly string $accountId, public readonly string $conversationId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(ProviderRegistry $providers, ConversationProviderAuth $auths): void
    {
        CurrentAccount::runAs($this->accountId, function () use ($providers, $auths): void {
            $conversation = Conversation::query()->find($this->conversationId);

            $session = $conversation?->provider_session_ref;

            if ($conversation === null || $session === null || $conversation->released_at !== null) {
                return;
            }

            $this->queueReconciliation($conversation);

            $auth = $auths->for($conversation);

            if ($auth !== null && $providers->get($conversation->provider)->teardown($session, $conversation->provider_context_ref, $auth)) {
                Conversation::query()->whereKey($conversation->id)->whereNull('released_at')->update(['released_at' => now()]);

                return;
            }

            $this->retryOrGiveUp();
        });
    }

    /**
     * The job died on something other than a teardown that said no: only the kind of failure is logged.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Provider session release failed', ['conversation_id' => $this->conversationId, 'exception' => $exception::class]);
    }

    /**
     * Queues the transcript job once, on whichever attempt first gets here: a first attempt that died on the
     * lookup above must not leave the transcript pending for good. The mark is taken before the push and given
     * back when the push fails, so the next attempt tries again.
     */
    private function queueReconciliation(Conversation $conversation): void
    {
        $mark = "hra:reconcile-queued:{$conversation->id}";

        if (! Cache::add($mark, true, self::QUEUED_MARK_SECONDS)) {
            return;
        }

        $due = ($conversation->ended_at ?? now())->getTimestamp() + self::EVENTS_GRACE_SECONDS;

        try {
            ReconcileTranscript::dispatch($this->accountId, $conversation->id)->delay(max(0, $due - now()->getTimestamp()));
        } catch (Throwable $pushFailed) {
            Cache::forget($mark);

            throw $pushFailed;
        }
    }

    private function retryOrGiveUp(): void
    {
        $backoff = $this->backoff();

        if ($this->attempts() <= count($backoff)) {
            $this->release($backoff[$this->attempts() - 1]);

            return;
        }

        // The conversation stays as it is; the provider session is left for the provider to expire.
        Log::warning('Provider session could not be released', ['conversation_id' => $this->conversationId, 'attempts' => $this->attempts()]);
    }
}
