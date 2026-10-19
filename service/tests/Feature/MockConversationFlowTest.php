<?php

use App\Conversations\ConversationStatus;
use App\Conversations\TranscriptStatus;
use App\Models\Conversation;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
 * The whole path of a conversation on the Mock provider, over HTTP, with the real synchronous queue: a
 * bootstrapped account creates keys, a config, an endpoint and a conversation; a browser connects, talks
 * and ends it; the release, the reconciliation and the webhooks all run as the jobs they are.
 */
it('runs a conversation from the first key to the transcript', function () {
    Http::fake(['receiver.example.com/*' => Http::response('', 204)]);

    // 1. A key, the way an operator gets the first one.
    expect(Artisan::call('hra:bootstrap-account', ['name' => 'Demo']))->toBe(0);
    preg_match('/hra_sk_live_[0-9a-f]{96}/', Artisan::output(), $match);
    $secretKey = ['Authorization' => 'Bearer '.$match[0]];

    // 2. A publishable key for the browser, a Mock config and a webhook endpoint.
    $publishable = $this->postJson('/v1/api-keys', ['type' => 'publishable', 'mode' => 'live', 'allowed_origins' => ['https://app.example.com']], $secretKey)->assertCreated()->json('key');
    $config = $this->postJson('/v1/avatar-configs', ['name' => 'Demo avatar', 'provider' => 'mock', 'config' => new stdClass], $secretKey)->assertCreated()->json('id');
    $this->postJson('/v1/webhook-endpoints', ['url' => 'https://receiver.example.com/hook'], $secretKey)->assertCreated();

    // 3. The host creates a conversation.
    $created = $this->postJson('/v1/conversations', [
        'avatar_config_id' => $config, 'system_prompt' => 'Be kind.', 'opening_text' => 'Welcome!', 'language' => 'en', 'external_ref' => 'order-9', 'metadata' => ['a' => ['b' => 1]],
    ], $secretKey)->assertCreated();
    $id = $created->json('id');
    $browser = browserHeaders($publishable, $created->json('client_secret'));
    expect($created->json('status'))->toBe('created');

    // 4. The browser connects, signals it is live, talks, and ends it.
    $join = $this->postJson("/v1/client/conversations/{$id}/connect", [], $browser)->assertOk();
    expect($join->json('transport'))->toBe('mock');
    $this->getJson("/v1/conversations/{$id}", $secretKey)->assertJsonPath('status', 'connecting');

    $this->postJson("/v1/client/conversations/{$id}/events", ['events' => [
        ['idempotency_key' => 'e1', 'type' => 'lifecycle', 'name' => 'live'],
        ['idempotency_key' => 'e2', 'type' => 'utterance', 'role' => 'user', 'text' => 'Hello there', 'occurred_at' => '2026-10-19T12:00:10Z'],
        ['idempotency_key' => 'e3', 'type' => 'utterance', 'role' => 'avatar', 'text' => 'Nice to meet you', 'occurred_at' => '2026-10-19T12:00:12Z'],
    ]], $browser)->assertOk()->assertJson(['accepted' => 3, 'duplicates' => 0]);
    $this->getJson("/v1/conversations/{$id}", $secretKey)->assertJsonPath('status', 'live');

    $this->postJson("/v1/client/conversations/{$id}/end", [], $browser)->assertOk()->assertJsonPath('ended_reason', 'client');

    // 5. The release and the reconciliation have run: the Mock provider's own line was merged in.
    $transcript = $this->getJson("/v1/conversations/{$id}/transcript", $secretKey)->assertOk();
    expect($transcript->json('status'))->toBe('ready')
        ->and(array_map(fn ($u) => [$u['role'], $u['text'], $u['source']], $transcript->json('utterances')))->toBe([
            ['user', 'Hello there', 'client'],
            ['avatar', 'Nice to meet you', 'client'],
            ['avatar', 'Welcome!', 'provider'],
        ]);
    $stored = Conversation::withoutGlobalScopes()->findOrFail($id);
    expect($stored->status)->toBe(ConversationStatus::Ended)
        ->and($stored->transcript_status)->toBe(TranscriptStatus::Ready)
        ->and($stored->released_at)->not->toBeNull();

    // 6. The receiver was told, in order, and every delivery succeeded.
    $deliveries = WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $id)->orderBy('created_at')->orderBy('id')->get();
    expect($deliveries->map(fn (WebhookDelivery $delivery) => $delivery->event_type->value)->all())->toBe(['conversation.live', 'conversation.ended', 'transcript.ready'])
        ->and($deliveries->every(fn (WebhookDelivery $delivery) => $delivery->status->value === 'succeeded'))->toBeTrue();
    $ready = json_decode($deliveries->last()->payload, true);
    expect($ready['data']['transcript'])->toBe(['status' => 'ready', 'utterance_count' => 3])
        ->and($ready['data']['conversation']['external_ref'])->toBe('order-9');

    // 7. Ending again changes nothing.
    $this->postJson("/v1/conversations/{$id}/end", [], $secretKey)->assertOk()->assertJsonPath('ended_reason', 'client');
    expect(WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $id)->count())->toBe(3);
});

it('runs a conversation that is ended by the host before the browser ever connects', function () {
    Http::fake(['receiver.example.com/*' => Http::response('', 204)]);
    Artisan::call('hra:bootstrap-account', ['name' => 'Demo']);
    preg_match('/hra_sk_live_[0-9a-f]{96}/', Artisan::output(), $match);
    $secretKey = ['Authorization' => 'Bearer '.$match[0]];
    $config = $this->postJson('/v1/avatar-configs', ['name' => 'Demo avatar', 'provider' => 'mock', 'config' => new stdClass], $secretKey)->assertCreated()->json('id');
    $this->postJson('/v1/webhook-endpoints', ['url' => 'https://receiver.example.com/hook'], $secretKey)->assertCreated();
    $id = $this->postJson('/v1/conversations', ['avatar_config_id' => $config, 'system_prompt' => 'Be kind.', 'language' => 'en'], $secretKey)->assertCreated()->json('id');

    $this->postJson("/v1/conversations/{$id}/end", [], $secretKey)->assertOk()->assertJsonPath('ended_reason', 'api')->assertJsonPath('transcript_status', 'ready');

    $this->getJson("/v1/conversations/{$id}/transcript", $secretKey)->assertOk()->assertJson(['status' => 'ready', 'utterances' => []]);
    expect(WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $id)->get()->map(fn (WebhookDelivery $delivery) => $delivery->event_type->value)->sort()->values()->all())
        ->toBe(['conversation.ended', 'transcript.ready']);
});
