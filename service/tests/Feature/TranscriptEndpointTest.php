<?php

use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptStatus;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['conversations:read']]);
    [, $this->writeOnly] = issueKey($this->account, ['scopes' => ['conversations:write']]);
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function transcriptConversation(ConversationStatus $status = ConversationStatus::Ended, TranscriptStatus $transcript = TranscriptStatus::Ready, array $attributes = [], ?Account $account = null): Conversation
{
    $account ??= test()->account;
    $config = $account->is(test()->account) ? test()->config : inAccount($account, fn () => AvatarConfig::factory()->create());
    $implied = $status === ConversationStatus::Created ? [] : ['connecting_at' => now()->subMinutes(5), 'provider_session_ref' => 'sess-1'] + ($status->isTerminal() ? ['ended_at' => now()->subMinute(), 'ended_reason' => EndedReason::Api] : []);

    return inAccount($account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id, 'status' => $status, 'transcript_status' => $transcript, ...$implied, ...$attributes]));
}

function transcriptLine(Conversation $conversation, int $sequence, string $text, ?string $at, string $source = 'client', string $role = 'user'): void
{
    inAccount(Account::findOrFail($conversation->account_id), fn () => ConversationEvent::forceCreate([
        'conversation_id' => $conversation->id, 'sequence' => $sequence, 'source' => $source, 'type' => 'utterance', 'role' => $role, 'text' => $text,
        'idempotency_key' => "k-{$sequence}",
    ]));
    // Written as text so the model's whole-second date format does not cut the milliseconds.
    DB::table('conversation_events')->where('conversation_id', $conversation->id)->where('sequence', $sequence)->update(['occurred_at' => $at === null ? null : Carbon::parse($at)->utc()->format('Y-m-d H:i:s.u')]);
}

function readTranscript(string $id, ?string $raw = null): TestResponse
{
    return test()->getJson("/v1/conversations/{$id}/transcript", ['Authorization' => 'Bearer '.($raw ?? test()->raw)]);
}

it('returns the status and the utterances with the documented members only', function () {
    $conversation = transcriptConversation();
    transcriptLine($conversation, 1, 'Hello', '2026-10-19T12:00:00Z');
    transcriptLine($conversation, 2, 'Hi there', '2026-10-19T12:00:05.250Z', 'provider', 'avatar');

    $response = readTranscript($conversation->id)->assertOk();

    expect(array_keys($response->json()))->toBe(['conversation_id', 'status', 'utterances'])
        ->and($response->json('conversation_id'))->toBe($conversation->id)
        ->and($response->json('status'))->toBe('ready')
        ->and($response->json('utterances'))->toBe([
            ['sequence' => 1, 'role' => 'user', 'text' => 'Hello', 'occurred_at' => '2026-10-19T12:00:00.000Z', 'source' => 'client'],
            ['sequence' => 2, 'role' => 'avatar', 'text' => 'Hi there', 'occurred_at' => '2026-10-19T12:00:05.250Z', 'source' => 'provider'],
        ]);
});

it('orders by time, earliest first, then by sequence, and puts the lines without a time last', function () {
    $conversation = transcriptConversation();
    transcriptLine($conversation, 1, 'no time, first arrived', null);
    transcriptLine($conversation, 2, 'late', '2026-10-19T12:00:30Z');
    transcriptLine($conversation, 3, 'early', '2026-10-19T12:00:10Z');
    transcriptLine($conversation, 4, 'same time, second', '2026-10-19T12:00:20Z');
    transcriptLine($conversation, 5, 'no time, second arrived', null);
    transcriptLine($conversation, 6, 'same time, first', '2026-10-19T12:00:20Z');

    $texts = array_column(readTranscript($conversation->id)->assertOk()->json('utterances'), 'text');

    expect($texts)->toBe(['early', 'same time, second', 'same time, first', 'late', 'no time, first arrived', 'no time, second arrived']);
});

it('leaves the lifecycle events out', function () {
    $conversation = transcriptConversation();
    transcriptLine($conversation, 1, 'Hello', null);
    inAccount($this->account, fn () => ConversationEvent::forceCreate([
        'conversation_id' => $conversation->id, 'sequence' => 2, 'source' => 'client', 'type' => 'lifecycle', 'name' => 'live', 'idempotency_key' => 'live-1',
    ]));

    expect(readTranscript($conversation->id)->assertOk()->json('utterances'))->toHaveCount(1);
});

it('reports the status the transcript has: pending, ready or partial', function (TranscriptStatus $status) {
    $conversation = transcriptConversation(transcript: $status);
    transcriptLine($conversation, 1, 'Hello', null);

    $response = readTranscript($conversation->id)->assertOk();

    expect($response->json('status'))->toBe($status->value)->and($response->json('utterances'))->toHaveCount(1);
})->with([TranscriptStatus::Pending, TranscriptStatus::Ready, TranscriptStatus::Partial]);

it('is pending, with what the browser has sent so far, for a conversation that is still going', function (ConversationStatus $status) {
    $conversation = transcriptConversation($status, TranscriptStatus::Pending);
    transcriptLine($conversation, 1, 'So far', null);

    $response = readTranscript($conversation->id)->assertOk();

    expect($response->json('status'))->toBe('pending')->and(array_column($response->json('utterances'), 'text'))->toBe(['So far']);
})->with([ConversationStatus::Connecting, ConversationStatus::Live]);

it('answers 409 invalid_state for a conversation that was not connected', function () {
    $conversation = transcriptConversation(ConversationStatus::Created, TranscriptStatus::Pending);

    $response = readTranscript($conversation->id)->assertStatus(409);

    expect($response->json('code'))->toBe('invalid_state');
});

it('returns an empty list for a transcript with nothing in it', function () {
    $conversation = transcriptConversation();

    expect(readTranscript($conversation->id)->assertOk()->json('utterances'))->toBe([]);
});

it('does not mix up the utterances of two conversations', function () {
    $first = transcriptConversation();
    $second = transcriptConversation();
    transcriptLine($first, 1, 'first', null);
    transcriptLine($second, 1, 'second', null);

    expect(array_column(readTranscript($second->id)->json('utterances'), 'text'))->toBe(['second']);
});

it('answers 404 for a conversation of another account', function () {
    $foreign = transcriptConversation(account: Account::factory()->create());
    transcriptLine($foreign, 1, 'secret words', null);

    $response = readTranscript($foreign->id)->assertNotFound();

    expect($response->json('code'))->toBe('not_found')->and($response->getContent())->not->toContain('secret words');
});

it('answers 404 for a conversation that does not exist', function () {
    readTranscript('conv_missing')->assertNotFound();
});

it('needs the conversations:read scope and a key', function () {
    $conversation = transcriptConversation();

    expect(readTranscript($conversation->id, $this->writeOnly)->assertForbidden()->json('code'))->toBe('insufficient_scope');
    $this->getJson("/v1/conversations/{$conversation->id}/transcript")->assertUnauthorized();
});
