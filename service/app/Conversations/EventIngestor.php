<?php

namespace App\Conversations;

use App\Accounts\CurrentAccount;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Stores the browser's events for a conversation, once each.
 *
 * Everything happens under the conversation's row lock: the arrival order (`sequence`) is numbered
 * from the highest stored, so two batches, or a batch and the transcript merge, never share a number.
 * A key already stored is a duplicate and is not stored again. Events are taken while the conversation
 * is open and for a minute after it ended (the browser flushes its last ones as it closes); an utterance
 * is not taken before the conversation is connected. A `live` signal of a connecting conversation makes
 * it live; any other signal is stored and has no effect.
 */
final class EventIngestor
{
    /** How long after the end the browser may still send events. */
    public const GRACE_SECONDS = 60;

    public function __construct(private readonly ConversationLifecycle $lifecycle) {}

    /**
     * @param  list<array{idempotency_key: string, type: string, role: string|null, text: string|null, name: string|null, data: stdClass|null, occurred_at: string|null}>  $events
     * @return array{accepted: int, duplicates: int}
     *
     * @throws ApiProblem `conversation_closed` or `invalid_state`
     */
    public function ingest(Conversation $conversation, array $events): array
    {
        return CurrentAccount::runAs($conversation->account_id, fn (): array => DB::transaction(function () use ($conversation, $events): array {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);

            $this->guard($locked, $events);

            $known = array_flip(ConversationEvent::query()
                ->where('conversation_id', $locked->id)
                ->whereIn('idempotency_key', array_column($events, 'idempotency_key'))
                ->pluck('idempotency_key')->all());
            $sequence = (int) ConversationEvent::query()->where('conversation_id', $locked->id)->max('sequence');
            $rows = [];
            $duplicates = 0;
            $live = false;

            foreach ($events as $event) {
                if (isset($known[$event['idempotency_key']])) {
                    $duplicates++;

                    continue;
                }

                $known[$event['idempotency_key']] = true;
                $live = $live || ($event['type'] === 'lifecycle' && $event['name'] === 'live');
                $rows[] = [
                    'account_id' => $locked->account_id,
                    'conversation_id' => $locked->id,
                    'sequence' => ++$sequence,
                    'source' => 'client',
                    'type' => $event['type'],
                    'role' => $event['role'],
                    'text' => $event['text'],
                    'name' => $event['name'],
                    'data' => $event['data'] === null ? null : json_encode($event['data'], JSON_THROW_ON_ERROR),
                    'occurred_at' => $event['occurred_at'],
                    'idempotency_key' => $event['idempotency_key'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            ConversationEvent::query()->insert($rows);

            if ($live && $locked->status === ConversationStatus::Connecting) {
                $this->lifecycle->transition($locked, ConversationStatus::Live);
            }

            return ['accepted' => count($rows), 'duplicates' => $duplicates];
        }));
    }

    /**
     * @param  list<array{type: string}>  $events
     *
     * @throws ApiProblem
     */
    private function guard(Conversation $conversation, array $events): void
    {
        if ($conversation->status->isTerminal() && $conversation->ended_at?->addSeconds(self::GRACE_SECONDS)->isPast()) {
            throw new ApiProblem(ErrorCode::ConversationClosed);
        }

        if ($conversation->status === ConversationStatus::Created && in_array('utterance', array_column($events, 'type'), true)) {
            throw new ApiProblem(ErrorCode::InvalidState);
        }
    }
}
