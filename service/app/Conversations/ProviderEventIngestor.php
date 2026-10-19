<?php

namespace App\Conversations;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\ProviderEvent;
use App\AvatarProviders\ProviderEventKind;
use App\Jobs\ProcessProviderEvent;
use App\Models\Conversation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Stores provider events as receipts, once each, and queues their processing.
 *
 * The receipt comes first: it is what makes an event durable before anyone is told it was received. An
 * event whose (provider, key) is already stored inserts nothing and queues nothing, decided by the unique
 * key in the same statement and not by a read before it, so concurrent copies of one event are stored and
 * processed once. An event the service takes no action on (`ignored`) is stored as already processed. The
 * receipt belongs to the conversation and account the caller authenticated against.
 */
final class ProviderEventIngestor
{
    /**
     * @param  list<ProviderEvent>  $events
     * @return int how many receipts were stored (an event stored before does not count)
     */
    public function ingest(Conversation $conversation, array $events): int
    {
        return CurrentAccount::runAs($conversation->account_id, fn (): int => DB::transaction(function () use ($conversation, $events): int {
            $stored = 0;

            foreach ($events as $event) {
                $id = strtolower((string) Str::ulid());
                $ignored = $event->kind === ProviderEventKind::Ignored;

                $inserted = DB::table('provider_webhook_receipts')->insertOrIgnore([
                    'id' => $id,
                    'account_id' => $conversation->account_id,
                    'conversation_id' => $conversation->id,
                    'provider' => $event->provider,
                    'provider_ref' => $event->providerRef,
                    'idempotency_key' => $event->idempotencyKey,
                    'kind' => $event->kind->value,
                    'occurred_at' => Carbon::instance($event->occurredAt)->utc()->format('Y-m-d H:i:s.u'),
                    'provider_end_reason' => $event->providerEndReason,
                    'payload' => json_encode($event->payload === [] ? new stdClass : $event->payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    'processed_at' => $ignored ? now() : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted === 1) {
                    $stored++;

                    if (! $ignored) {
                        ProcessProviderEvent::dispatch($conversation->account_id, $id)->afterCommit();
                    }
                }
            }

            return $stored;
        }));
    }
}
