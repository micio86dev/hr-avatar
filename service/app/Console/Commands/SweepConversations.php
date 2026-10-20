<?php

namespace App\Console\Commands;

use App\Accounts\CurrentAccount;
use App\Conversations\ConversationLifecycle;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Keys\ClientSecret;
use App\Models\Account;
use App\Models\Conversation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ends the conversations that must not go on: those that ran past their maximum duration (plus a margin
 * for the browser's own timer, ended as `max_duration`) and those that were created and never connected
 * within their connect window (ended as `expired`).
 *
 * Every ending goes through the lifecycle, so a sweeper racing another sweeper, an explicit end or a provider
 * event ends a conversation once: the first writer wins and only the winner emits. One account at a time:
 * every conversation is read and ended under the account it belongs to, never across accounts (the cost is
 * two indexed queries per account per run, which is small until the number of accounts is large). One
 * conversation that cannot be ended does not stop the others; it is logged by kind and makes the command exit
 * with a failure.
 */
#[Signature('hra:conversations:sweep')]
#[Description('End conversations that overran their maximum duration or were never connected')]
class SweepConversations extends Command
{
    /** How long past `connecting_at + max_duration_seconds` a conversation may still be running, for the browser's own timer. */
    private const MARGIN_SECONDS = 30;

    private const CHUNK = 100;

    public function handle(ConversationLifecycle $lifecycle): int
    {
        $ended = 0;
        $failed = 0;

        // ponytail: scoped queries per account per run, so the cost grows with the number of accounts.
        // A platform due-index would read across accounts, which the boundary rule (CLAUDE.md: only the key
        // resolver and the inbound token lookup are unscoped) must be extended to allow first.
        Account::query()->select('id')->lazyById(self::CHUNK)->each(function (Account $account) use ($lifecycle, &$ended, &$failed): void {
            CurrentAccount::runAs($account->id, function () use ($lifecycle, &$ended, &$failed): void {
                $overran = Conversation::query();
                $this->overran($overran);
                $abandoned = Conversation::query();
                $this->neverConnected($abandoned);

                foreach ([[$overran, EndedReason::MaxDuration], [$abandoned, EndedReason::Expired]] as [$query, $reason]) {
                    $query->chunkById(self::CHUNK, function (Collection $conversations) use ($lifecycle, $reason, &$ended, &$failed): void {
                        foreach ($conversations as $conversation) {
                            try {
                                $ended += $lifecycle->end($conversation, $reason) ? 1 : 0;
                            } catch (Throwable $failure) {
                                $failed++;
                                // Only the kind of failure: an exception's message may hold anything.
                                Log::error('Conversation sweep failed', ['conversation_id' => $conversation->id, 'exception' => $failure::class]);
                            }
                        }
                    });
                }
            });
        });

        $this->info("Ended {$ended} conversation(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Restricts a query to the conversations that are connected, still open, and past their maximum duration plus the margin.
     *
     * @param  Builder<Conversation>  $query
     */
    private function overran(Builder $query): void
    {
        $query
            ->whereIn('status', [ConversationStatus::Connecting->value, ConversationStatus::Live->value])
            ->whereRaw("connecting_at + (max_duration_seconds + ?) * interval '1 second' < ?", [self::MARGIN_SECONDS, now()]);
    }

    /**
     * Restricts a query to the conversations that were created and not connected within the connect window.
     *
     * @param  Builder<Conversation>  $query
     */
    private function neverConnected(Builder $query): void
    {
        $query->where('status', ConversationStatus::Created->value)->where('created_at', '<', now()->subSeconds(ClientSecret::CONNECT_WINDOW_SECONDS));
    }
}
