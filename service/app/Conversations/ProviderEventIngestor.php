<?php

namespace App\Conversations;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\ProviderEvent;
use App\AvatarProviders\ProviderEventKind;
use App\Jobs\ProcessProviderEvent;
use App\Models\Conversation;
use App\Models\ProviderWebhookReceipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Stores provider events as receipts, once each, and queues their processing.
 *
 * The receipt comes first: it is what makes an event durable before anyone is told it was received. An
 * event whose (provider, key) is already stored inserts nothing, decided by the unique key in the same
 * statement and not by a read before it, so concurrent copies of one event are stored and processed once. A
 * stored receipt that is still unprocessed after `STALE_SECONDS` (its job was lost) is queued again by the next
 * copy of the event, or by the `hra:relay` command, under a lease so two callers never both queue it. An event the service takes no action on (`ignored`) is stored as already processed. The
 * receipt belongs to the conversation and account the caller authenticated against.
 */
final class ProviderEventIngestor
{
    /**
     * How long a stored receipt may stay unprocessed before it is queued again (by a redelivery of the event or by
     * the relay). A processing job normally finishes in seconds; the processing is idempotent, so a duplicate job
     * is harmless, and this only bounds how often a slow one is doubled.
     */
    public const STALE_SECONDS = 60;

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

                    continue;
                }

                // Stored before. If its processing never happened (the push failed, the job died), this copy is
                // the chance to queue it: the provider's retry must not be answered with a receipt nobody will read.
                $this->requeueStored($event);
            }

            return $stored;
        }));
    }

    private function requeueStored(ProviderEvent $event): void
    {
        $receipt = ProviderWebhookReceipt::query()
            ->where('provider', $event->provider)
            ->where('idempotency_key', $event->idempotencyKey)
            ->whereNull('processed_at')
            ->first();

        if ($receipt !== null) {
            $this->requeue($receipt);
        }
    }

    /**
     * Queues the processing of a stored receipt again, when it is still unprocessed and has been left for
     * `STALE_SECONDS`. The claim is one conditional UPDATE of the receipt's `updated_at`, so of two callers
     * (a redelivery, the relay) exactly one queues it. Call it under the receipt's account.
     *
     * @return bool whether this call queued it
     */
    public function requeue(ProviderWebhookReceipt $receipt): bool
    {
        $claimed = ProviderWebhookReceipt::query()
            ->whereKey($receipt->id)
            ->whereNull('processed_at')
            ->where('updated_at', '<', now()->subSeconds(self::STALE_SECONDS))
            ->update(['updated_at' => now()]) === 1;

        if ($claimed) {
            ProcessProviderEvent::dispatch($receipt->account_id, $receipt->id)->afterCommit();
        }

        return $claimed;
    }
}
