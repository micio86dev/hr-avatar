<?php

namespace App\Console\Commands;

use App\Accounts\CurrentAccount;
use App\Conversations\ProviderEventIngestor;
use App\Jobs\DeliverWebhook;
use App\Models\Account;
use App\Models\ProviderWebhookReceipt;
use App\Models\WebhookDelivery;
use App\Webhooks\Outbound\DeliveryStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues again the work whose job was lost. A row is committed first and its job pushed afterwards, so a queue
 * that was down at the wrong moment, or a worker killed between two writes, leaves a row that nothing will ever
 * pick up: a webhook delivery that is `pending` (never pushed, or a retry written and never queued) and a
 * provider event that is stored and never processed.
 *
 * A row is only taken once it is stale: a delivery that has had no attempt scheduled, or whose scheduled attempt
 * is overdue, for `STALE_SECONDS`; a receipt untouched for `ProviderEventIngestor::STALE_SECONDS` and younger than
 * `GIVE_UP_SECONDS` (an event that has not been processed in a day is not made to loop forever). The relay then
 * claims it with one conditional UPDATE that moves the row's lease forward (a delivery's `next_attempt_at`, a
 * receipt's `updated_at`), and only the claimer queues the job, so two relays never queue one row twice and a
 * failed push is retried once the lease has run out. A duplicate job (the original was only slow) is harmless:
 * a delivery's writes are conditional on the attempt count, a receipt's processing is idempotent.
 *
 * One account at a time, every row read and claimed under its account. A row that cannot be queued is logged by
 * kind and makes the command exit with a failure after the rest are done.
 */
#[Signature('hra:relay')]
#[Description('Queue again the webhook deliveries and provider events whose job was lost')]
class RelayLostJobs extends Command
{
    /** How long a delivery may sit without an attempt scheduled, or past its scheduled attempt, before it is taken. */
    public const STALE_SECONDS = 300;

    /** A receipt older than this is left alone: processing it has failed for a day, and a person has to look. */
    public const GIVE_UP_SECONDS = 86400;

    private const CHUNK = 100;

    public function handle(ProviderEventIngestor $receipts): int
    {
        $deliveries = 0;
        $events = 0;
        $failed = 0;

        // ponytail: scoped queries per account per run, so the cost grows with the number of accounts.
        // A platform due-index would read across accounts, which the boundary rule (CLAUDE.md: only the key
        // resolver and the inbound token lookup are unscoped) must be extended to allow first.
        Account::query()->select('id')->lazyById(self::CHUNK)->each(function (Account $account) use ($receipts, &$deliveries, &$events, &$failed): void {
            CurrentAccount::runAs($account->id, function () use ($account, $receipts, &$deliveries, &$events, &$failed): void {
                $this->stale(WebhookDelivery::query())->chunkById(self::CHUNK, function (Collection $rows) use ($account, &$deliveries, &$failed): void {
                    foreach ($rows as $delivery) {
                        $this->attempt('delivery', $delivery->id, $deliveries, $failed, function () use ($account, $delivery): bool {
                            if (! $this->claimDelivery($delivery)) {
                                return false;
                            }

                            DeliverWebhook::dispatch($account->id, $delivery->id);

                            return true;
                        });
                    }
                });

                ProviderWebhookReceipt::query()
                    ->whereNull('processed_at')
                    ->where('created_at', '>', now()->subSeconds(self::GIVE_UP_SECONDS))
                    ->where('updated_at', '<', now()->subSeconds(ProviderEventIngestor::STALE_SECONDS))
                    ->chunkById(self::CHUNK, function (Collection $rows) use ($receipts, &$events, &$failed): void {
                        foreach ($rows as $receipt) {
                            $this->attempt('provider event', $receipt->id, $events, $failed, fn (): bool => $receipts->requeue($receipt));
                        }
                    });
            });
        });

        $this->info("Queued {$deliveries} delivery(ies) and {$events} provider event(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Restricts a query to the `pending` deliveries that have gone stale.
     *
     * @param  Builder<WebhookDelivery>  $query
     * @return Builder<WebhookDelivery>
     */
    private function stale(Builder $query): Builder
    {
        $cutoff = now()->subSeconds(self::STALE_SECONDS);

        return $query->where('status', DeliveryStatus::Pending->value)->where(
            fn (Builder $due) => $due->where('next_attempt_at', '<', $cutoff)
                ->orWhere(fn (Builder $first) => $first->whereNull('next_attempt_at')->where('created_at', '<', $cutoff))
        );
    }

    /**
     * The claim: the same staleness test, as the condition of the UPDATE that moves the lease. One relay wins it.
     */
    private function claimDelivery(WebhookDelivery $delivery): bool
    {
        return $this->stale(WebhookDelivery::query()->whereKey($delivery->id))->update(['next_attempt_at' => now()]) === 1;
    }

    /**
     * Runs one row's claim and push. Counts it when it was queued; a failure is logged by kind and counted.
     *
     * @param  callable(): bool  $queue  true when this call queued the row
     */
    private function attempt(string $what, string $id, int &$queued, int &$failed, callable $queue): void
    {
        try {
            $queued += $queue() ? 1 : 0;
        } catch (Throwable $failure) {
            $failed++;
            // Only the kind of failure: an exception's message may hold a connection string.
            Log::error('Relay failed', ['what' => $what, 'id' => $id, 'exception' => $failure::class]);
        }
    }
}
