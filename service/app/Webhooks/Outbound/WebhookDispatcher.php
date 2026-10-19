<?php

namespace App\Webhooks\Outbound;

use App\Accounts\CurrentAccount;
use App\Jobs\DeliverWebhook;
use App\Models\Conversation;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns something that happened to a conversation into one delivery per interested endpoint.
 *
 * Meant to be called inside the transaction that made the change: the rows commit or roll back with
 * it, and the delivery jobs are queued only once it has committed, so a request never waits for a
 * receiver and a receiver is never told about a change that was rolled back.
 */
final class WebhookDispatcher
{
    public function __construct(private readonly WebhookSigner $signer) {}

    /**
     * Records the event for the account's enabled endpoints that subscribe to it. Recording the same
     * event of the same conversation again creates nothing.
     *
     * @param  int  $utteranceCount  the number of utterances in the transcript, for `transcript.ready` only
     * @return list<string> the ids of the deliveries created
     */
    public function record(Conversation $conversation, EventType $type, int $utteranceCount = 0): array
    {
        $created = CurrentAccount::runAs($conversation->account_id, function () use ($conversation, $type, $utteranceCount): array {
            $endpoints = WebhookEndpoint::query()->where('enabled', true)->whereJsonContains('events', $type->value)->pluck('id');

            if ($endpoints->isEmpty()) {
                return [];
            }

            $eventId = 'evt_'.strtolower((string) Str::ulid());
            $payload = $this->signer->encode($this->body($conversation, $type, $eventId, $utteranceCount));
            $created = [];

            foreach ($endpoints as $endpointId) {
                $id = strtolower((string) Str::ulid());

                // Ignored when this endpoint already has the event for this conversation: the unique key decides, not a prior read.
                $inserted = DB::table('webhook_deliveries')->insertOrIgnore([
                    'id' => $id,
                    'account_id' => $conversation->account_id,
                    'webhook_endpoint_id' => $endpointId,
                    'conversation_id' => $conversation->id,
                    'event_id' => $eventId,
                    'event_type' => $type->value,
                    'payload' => $payload,
                    'status' => DeliveryStatus::Pending->value,
                    'attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted === 1) {
                    $created[] = $id;
                }
            }

            return $created;
        });

        foreach ($created as $id) {
            DeliverWebhook::dispatch($conversation->account_id, $id)->afterCommit();
        }

        return $created;
    }

    /**
     * The envelope. Nothing of the provider's, no secret and no transcript text can be in it: it is built from
     * these members only.
     *
     * @return array<string, mixed>
     */
    private function body(Conversation $conversation, EventType $type, string $eventId, int $utteranceCount): array
    {
        $data = ['conversation' => [
            'id' => $conversation->id,
            'status' => $conversation->status->value,
            'avatar_config_id' => $conversation->avatar_config_id,
            'external_ref' => $conversation->external_ref,
            'metadata' => $conversation->metadata,
            'ended_reason' => $conversation->ended_reason?->value,
            'created_at' => $conversation->created_at->toIso8601ZuluString(),
            'live_at' => $conversation->live_at?->toIso8601ZuluString(),
            'ended_at' => $conversation->ended_at?->toIso8601ZuluString(),
            'transcript_status' => $conversation->transcript_status->value,
        ]];

        if ($type === EventType::TranscriptReady) {
            $data['transcript'] = ['status' => $conversation->transcript_status->value, 'utterance_count' => $utteranceCount];
        }

        return ['id' => $eventId, 'type' => $type->value, 'created_at' => now()->toIso8601ZuluString(), 'data' => $data];
    }
}
