<?php

namespace App\Console\Commands;

use App\Accounts\CurrentAccount;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\ProviderEvent;
use App\AvatarProviders\ProviderEventKind;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ProviderRegistry;
use App\Conversations\ConversationProviderAuth;
use App\Conversations\ConversationStatus;
use App\Conversations\ProviderEventIngestor;
use App\Models\Account;
use App\Models\Conversation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the providers that send no events whether their open sessions have ended.
 *
 * For every open conversation that holds a session with a provider whose adapter says its events are polled,
 * it asks the adapter for the session's status. A session that has ended becomes an `ended` event keyed by the
 * provider's event prefix and the session, stored and processed like any callback, so asking again after the
 * first answer stores nothing new. A provider that fails is not an end: it is logged by status class and the
 * conversation is left for the next run. A conversation whose credential is missing is skipped and named in
 * the log. Conversations of providers that call back, or send nothing, are never touched, and neither are
 * conversations that are over. One account at a time, every conversation read under its account; one that
 * cannot be handled is logged by kind and makes the command exit with a failure after the rest are done.
 */
#[Signature('hra:conversations:poll')]
#[Description('Ask providers without webhooks whether their open sessions have ended')]
class PollProviderSessions extends Command
{
    private const CHUNK = 100;

    public function handle(ProviderRegistry $providers, ConversationProviderAuth $auths, ProviderEventIngestor $ingestor): int
    {
        $polled = $providers->withIngestion(Ingestion::Poll);
        $failed = 0;

        if ($polled !== []) {
            Account::query()->select('id')->lazyById(self::CHUNK)->each(function (Account $account) use ($providers, $auths, $ingestor, $polled, &$failed): void {
                CurrentAccount::runAs($account->id, function () use ($providers, $auths, $ingestor, $polled, &$failed): void {
                    Conversation::query()
                        ->whereIn('provider', $polled)
                        ->whereIn('status', [ConversationStatus::Connecting->value, ConversationStatus::Live->value])
                        ->whereNotNull('provider_session_ref')
                        ->chunkById(self::CHUNK, function (Collection $conversations) use ($providers, $auths, $ingestor, &$failed): void {
                            foreach ($conversations as $conversation) {
                                try {
                                    $this->poll($conversation, $providers, $auths, $ingestor);
                                } catch (Throwable $failure) {
                                    $failed++;
                                    // Only the kind of failure: an exception's message may hold anything.
                                    Log::error('Provider session poll failed', ['conversation_id' => $conversation->id, 'exception' => $failure::class]);
                                }
                            }
                        });
                });
            });
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function poll(Conversation $conversation, ProviderRegistry $providers, ConversationProviderAuth $auths, ProviderEventIngestor $ingestor): void
    {
        $session = (string) $conversation->provider_session_ref;
        $auth = $auths->for($conversation);

        if ($auth === null) {
            Log::warning('Provider session lookup skipped', ['conversation_id' => $conversation->id, 'reason' => 'no_credential']);

            return;
        }

        try {
            $status = $providers->get($conversation->provider)->fetchSession($session, $auth);
        } catch (ProviderException $failure) {
            Log::warning('Provider session lookup failed', ['conversation_id' => $conversation->id, 'status_class' => $failure->statusClass()]);

            return;
        }

        if (! $status->ended) {
            return;
        }

        $ingestor->ingest($conversation, [new ProviderEvent(
            provider: $conversation->provider,
            providerRef: $session,
            kind: ProviderEventKind::Ended,
            occurredAt: now()->toDateTimeImmutable(),
            idempotencyKey: $providers->eventKeyPrefix($conversation->provider).":{$session}:ended",
            providerEndReason: $status->endReason,
            payload: ['source' => 'poll', 'end_reason' => $status->endReason],
        )]);
    }
}
