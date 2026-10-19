<?php

namespace App\Conversations;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\TranscriptLine;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Webhooks\Outbound\EventType;
use App\Webhooks\Outbound\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Finishes a conversation's transcript: adds what the provider heard and the browser did not forward,
 * marks the transcript `ready` (or `partial`) and records `transcript.ready`, all in one transaction.
 *
 * It merges and never replaces: every line the browser forwarded stays, and a provider line is added
 * only when no unmatched line of the same speaker and the same normalized text (trimmed, whitespace
 * collapsed, case-insensitive) exists. A line already stored accounts for one provider line only, so
 * an utterance repeated in the conversation is not lost.
 *
 * The first run to finish wins. The finishing write is a conditional UPDATE on the conversation (it
 * takes the row lock, so it also holds off the browser's event ingestion while lines are numbered); a
 * run that loses it, or that finds the conversation not yet over, changes nothing and records nothing.
 */
final class TranscriptReconciler
{
    public function __construct(private readonly WebhookDispatcher $webhooks) {}

    /**
     * @param  list<TranscriptLine>  $lines  what the provider has; empty to finish with what is stored
     * @param  TranscriptStatus  $outcome  `Ready`, or `Partial` when the provider's transcript could not be had
     * @return bool whether this call finished the transcript
     */
    public function reconcile(Conversation $conversation, array $lines, TranscriptStatus $outcome = TranscriptStatus::Ready): bool
    {
        if ($outcome === TranscriptStatus::Pending) {
            throw new InvalidArgumentException('A transcript is finished as ready or partial.');
        }

        return CurrentAccount::runAs($conversation->account_id, fn (): bool => DB::transaction(function () use ($conversation, $lines, $outcome): bool {
            $finished = Conversation::query()
                ->whereKey($conversation->id)
                ->where('transcript_status', TranscriptStatus::Pending->value)
                ->whereIn('status', array_column(array_filter(ConversationStatus::cases(), fn (ConversationStatus $status) => $status->isTerminal()), 'value'))
                ->update(['transcript_status' => $outcome->value]);

            if ($finished === 0) {
                return false;
            }

            $this->addMissing($conversation, $lines);

            $this->webhooks->record(
                Conversation::query()->findOrFail($conversation->id),
                EventType::TranscriptReady,
                ConversationEvent::query()->where('conversation_id', $conversation->id)->where('type', 'utterance')->count(),
            );

            return true;
        }));
    }

    /**
     * @param  list<TranscriptLine>  $lines
     */
    private function addMissing(Conversation $conversation, array $lines): void
    {
        $unmatched = [];

        foreach (ConversationEvent::query()->where('conversation_id', $conversation->id)->where('type', 'utterance')->get(['role', 'text']) as $stored) {
            $key = self::key((string) $stored->role, (string) $stored->text);
            $unmatched[$key] = ($unmatched[$key] ?? 0) + 1;
        }

        $sequence = (int) ConversationEvent::query()->where('conversation_id', $conversation->id)->max('sequence');
        $rows = [];

        foreach ($lines as $position => $line) {
            $key = self::key($line->role, $line->text);

            if (($unmatched[$key] ?? 0) > 0) {
                $unmatched[$key]--;

                continue;
            }

            $rows[] = [
                'account_id' => $conversation->account_id,
                'conversation_id' => $conversation->id,
                'sequence' => ++$sequence,
                'source' => 'provider',
                'type' => 'utterance',
                'role' => $line->role,
                'text' => $line->text,
                'occurred_at' => $line->occurredAt,
                'idempotency_key' => "provider:{$position}",
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            ConversationEvent::query()->insert($rows);
        }
    }

    private static function key(string $role, string $text): string
    {
        return $role."\0".mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
