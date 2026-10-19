<?php

namespace App\Conversations;

use App\Accounts\CurrentAccount;
use App\Jobs\ReleaseProviderSession;
use App\Models\Conversation;
use App\Webhooks\Outbound\EventType;
use App\Webhooks\Outbound\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The conversation state machine: the one place a conversation's status changes.
 *
 * ```
 * created -> connecting -> live -> ended | failed
 * created -> ended          connecting -> ended | failed
 * ```
 *
 * `ended` and `failed` are terminal and immutable. Every cause that can end a conversation (an explicit end,
 * the browser, the sweeper, a provider event) goes through `transition()`, which decides with one conditional
 * UPDATE: the row lock it takes lets exactly one cause win, and everyone after that finds the conversation in
 * the winner's state. The winner alone records the webhook deliveries and, for a conversation with nothing to
 * release, its finished transcript (database writes only, in the same transaction, so they commit or roll back
 * with the change); every job is queued once the change has committed.
 */
final class ConversationLifecycle
{
    public function __construct(private readonly WebhookDispatcher $webhooks, private readonly TranscriptReconciler $transcripts) {}

    /**
     * Moves a conversation to `$to`. The model passed in is refreshed to the state that holds afterwards,
     * whoever won.
     *
     * `$reason` is why a conversation ended (`Ended` takes one of the ordinary reasons) or failed (`Failed` takes
     * `ProviderError` or `ProviderCapacity`); no other target takes one. `$providerEndReason` is the provider's
     * own words for an end and is kept beside the stable reason.
     *
     * @return bool true when this call changed the conversation; false when it was already in `$to`, which is a
     *              no-op that records and queues nothing (the first writer wins)
     *
     * @throws IllegalTransition `$to` cannot be reached from where the conversation is
     * @throws InvalidArgumentException `$reason` or `$providerEndReason` does not fit `$to`
     */
    public function transition(Conversation $conversation, ConversationStatus $to, ?EndedReason $reason = null, ?string $providerEndReason = null): bool
    {
        $this->guardArguments($to, $reason, $providerEndReason);

        return CurrentAccount::runAs($conversation->account_id, fn (): bool => DB::transaction(function () use ($conversation, $to, $reason, $providerEndReason): bool {
            $changed = Conversation::query()
                ->whereKey($conversation->id)
                ->whereIn('status', array_column(self::sources($to), 'value'))
                ->update($this->values($to, $reason, $providerEndReason));

            $conversation->refresh();

            if ($changed === 0) {
                if ($conversation->status === $to) {
                    return false;
                }

                throw new IllegalTransition($conversation->status, $to);
            }

            $this->afterChange($conversation, $to);

            return true;
        }));
    }

    /**
     * Ends a conversation that is still open; a terminal one (a failed one included) is left exactly as it is.
     *
     * @return bool whether this call ended it
     */
    public function end(Conversation $conversation, EndedReason $reason, ?string $providerEndReason = null): bool
    {
        if ($conversation->status->isTerminal()) {
            return false;
        }

        try {
            return $this->transition($conversation, ConversationStatus::Ended, $reason, $providerEndReason);
        } catch (IllegalTransition) {
            // Another cause made it terminal (a failure) between the read and the write.
            return false;
        }
    }

    /**
     * The states a conversation may be in for the move to `$to`.
     *
     * @return list<ConversationStatus>
     */
    private static function sources(ConversationStatus $to): array
    {
        return match ($to) {
            ConversationStatus::Connecting => [ConversationStatus::Created],
            ConversationStatus::Live => [ConversationStatus::Connecting],
            ConversationStatus::Ended => [ConversationStatus::Created, ConversationStatus::Connecting, ConversationStatus::Live],
            ConversationStatus::Failed => [ConversationStatus::Connecting, ConversationStatus::Live],
            ConversationStatus::Created => [],
        };
    }

    private function guardArguments(ConversationStatus $to, ?EndedReason $reason, ?string $providerEndReason): void
    {
        $fits = match ($to) {
            ConversationStatus::Ended => $reason !== null && ! in_array($reason, [EndedReason::ProviderError, EndedReason::ProviderCapacity], true),
            ConversationStatus::Failed => in_array($reason, [EndedReason::ProviderError, EndedReason::ProviderCapacity], true),
            default => $reason === null,
        };

        if (! $fits) {
            throw new InvalidArgumentException('The reason does not fit the state the conversation is moved to.');
        }

        if ($providerEndReason !== null && $to !== ConversationStatus::Ended) {
            throw new InvalidArgumentException('A provider\'s reason belongs to an end.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function values(ConversationStatus $to, ?EndedReason $reason, ?string $providerEndReason): array
    {
        $now = now();

        return [
            'status' => $to->value,
            'updated_at' => $now,
            ...match ($to) {
                ConversationStatus::Connecting => ['connecting_at' => $now],
                ConversationStatus::Live => ['live_at' => $now],
                default => ['ended_at' => $now, 'ended_reason' => $reason?->value, 'provider_end_reason' => $providerEndReason],
            },
        ];
    }

    /**
     * What a change brings with it, inside its transaction: the webhook rows, and for a conversation that is
     * over either the immediate end of its transcript (no provider session was ever held) or the delayed
     * release of the session.
     */
    private function afterChange(Conversation $conversation, ConversationStatus $to): void
    {
        if ($to === ConversationStatus::Live) {
            $this->webhooks->record($conversation, EventType::ConversationLive);

            return;
        }

        if (! $to->isTerminal()) {
            return;
        }

        $this->webhooks->record($conversation, EventType::ConversationEnded);

        if ($conversation->provider_session_ref === null) {
            $this->transcripts->reconcile($conversation, []);
            $conversation->refresh();

            return;
        }

        ReleaseProviderSession::dispatch($conversation->account_id, $conversation->id)
            ->delay(config()->integer('hr-avatar.release_delay_seconds'))
            ->afterCommit();
    }
}
