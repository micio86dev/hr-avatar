<?php

use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptStatus;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->other = Account::factory()->create();
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
});

/**
 * A conversation row as the table holds it, inserted without the model.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function conversationRow(array $overrides = []): array
{
    return [
        'id' => 'conv_'.strtolower((string) Str::ulid()),
        'account_id' => test()->account->id,
        'avatar_config_id' => test()->config->id,
        'provider' => 'mock',
        'status' => 'created',
        'language' => 'en',
        'system_prompt' => 'Be brief.',
        'max_duration_seconds' => 1800,
        'metadata' => '{}',
        'transcript_status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function eventRow(string $conversationId, array $overrides = []): array
{
    return [
        'account_id' => test()->account->id,
        'conversation_id' => $conversationId,
        'sequence' => 1,
        'source' => 'client',
        'type' => 'utterance',
        'role' => 'user',
        'text' => 'Hello',
        'idempotency_key' => 'k-1',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

/**
 * Runs a statement that the database must refuse, inside a savepoint: a violation aborts the
 * surrounding transaction in PostgreSQL, and the test goes on afterwards.
 */
function refused(Closure $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

/**
 * @return list<string>
 */
function columnsOf(string $table): array
{
    return array_map(fn ($row) => $row->column_name, DB::select('select column_name from information_schema.columns where table_name = ? order by ordinal_position', [$table]));
}

function indexDefinitions(string $table): string
{
    return implode("\n", array_map(fn ($row) => $row->indexdef, DB::select('select indexdef from pg_indexes where tablename = ?', [$table])));
}

describe('conversations', function () {
    it('has the columns of the design, with metadata as json and not jsonb', function () {
        expect(columnsOf('conversations'))->toBe([
            'id', 'account_id', 'avatar_config_id', 'provider', 'status', 'language', 'system_prompt', 'opening_text',
            'max_duration_seconds', 'external_ref', 'metadata', 'provider_session_ref', 'provider_context_ref',
            'callback_token_hash', 'ended_reason', 'provider_end_reason', 'transcript_status', 'connecting_at',
            'live_at', 'ended_at', 'released_at', 'created_at', 'updated_at',
        ]);
        expect(DB::selectOne("select data_type from information_schema.columns where table_name = 'conversations' and column_name = 'metadata'")->data_type)->toBe('json');
    });

    it('accepts a row with the defaults', function () {
        $id = conversationRow()['id'];
        DB::table('conversations')->insert(conversationRow(['id' => $id]));

        $row = DB::table('conversations')->find($id);
        expect($row->status)->toBe('created')->and($row->transcript_status)->toBe('pending')->and($row->metadata)->toBe('{}');
    });

    it('keeps the callback token hash unique, and lets any number of conversations have none', function () {
        $hash = hash('sha256', 'token');
        DB::table('conversations')->insert(conversationRow(['callback_token_hash' => $hash]));
        DB::table('conversations')->insert(conversationRow());
        DB::table('conversations')->insert(conversationRow());

        refused(fn () => DB::table('conversations')->insert(conversationRow(['callback_token_hash' => $hash])));
    });

    it('has the three indexes the sweeper and the lists read', function () {
        $definitions = indexDefinitions('conversations');

        expect($definitions)->toContain('(account_id, created_at)')
            ->and($definitions)->toContain('(status, connecting_at)')
            ->and($definitions)->toContain('(status, created_at)')
            ->and($definitions)->toContain('UNIQUE INDEX')->and($definitions)->toContain('(callback_token_hash)');
    });

    it('accepts every status, ended reason and transcript status the state machine uses', function () {
        foreach (ConversationStatus::cases() as $status) {
            DB::table('conversations')->insert(conversationRow(['status' => $status->value]));
        }
        foreach (EndedReason::cases() as $reason) {
            DB::table('conversations')->insert(conversationRow(['status' => 'ended', 'ended_reason' => $reason->value]));
        }
        foreach (TranscriptStatus::cases() as $status) {
            DB::table('conversations')->insert(conversationRow(['transcript_status' => $status->value]));
        }

        expect(DB::table('conversations')->count())->toBe(count(ConversationStatus::cases()) + count(EndedReason::cases()) + count(TranscriptStatus::cases()));
    });

    it('names the values the checks allow', function () {
        expect(array_map(fn ($case) => $case->value, ConversationStatus::cases()))->toBe(['created', 'connecting', 'live', 'ended', 'failed'])
            ->and(array_map(fn ($case) => $case->value, EndedReason::cases()))->toBe(['client', 'api', 'max_duration', 'provider', 'expired', 'provider_error', 'provider_capacity'])
            ->and(array_map(fn ($case) => $case->value, TranscriptStatus::cases()))->toBe(['pending', 'ready', 'partial']);
    });

    it('rejects a row that breaks a constraint', function (array $overrides) {
        expect(fn () => DB::table('conversations')->insert(conversationRow($overrides)))->toThrow(QueryException::class);
    })->with([
        'unknown status' => [['status' => 'paused']],
        'unknown ended reason' => [['ended_reason' => 'because']],
        'unknown transcript status' => [['transcript_status' => 'done']],
        'a list as metadata' => [['metadata' => '[1,2]']],
        'a scalar as metadata' => [['metadata' => '"text"']],
        'a config that does not exist' => [['avatar_config_id' => 'avc_missing']],
        'an account that does not exist' => [['account_id' => '01J00000000000000000000000']],
    ]);

    it('keeps the config: it cannot be hard-deleted while a conversation references it, and a soft delete leaves the reference', function () {
        $id = conversationRow()['id'];
        DB::table('conversations')->insert(conversationRow(['id' => $id]));

        refused(fn () => DB::table('avatar_configs')->where('id', $this->config->id)->delete());

        inAccount($this->account, fn () => $this->config->delete());
        expect(DB::table('conversations')->find($id)->avatar_config_id)->toBe($this->config->id);
    });

    it('is invisible to another account and stamps the bound one', function () {
        $own = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => $this->config->id]));

        expect($own->account_id)->toBe($this->account->id)
            ->and($own->id)->toMatch('/^conv_[0-9a-z]{26}$/')
            ->and(inAccount($this->other, fn () => Conversation::query()->count()))->toBe(0)
            ->and(inAccount($this->other, fn () => Conversation::find($own->id)))->toBeNull()
            ->and(inAccount($this->account, fn () => Conversation::find($own->id)?->id))->toBe($own->id);
    });

    it('reads its enums, dates and metadata back as typed values', function () {
        $metadata = json_decode('{"a":{"b":1},"empty":{},"list":[]}', false);
        $created = inAccount($this->account, fn () => Conversation::factory()->create([
            'avatar_config_id' => $this->config->id,
            'status' => ConversationStatus::Ended,
            'ended_reason' => EndedReason::Api,
            'transcript_status' => TranscriptStatus::Partial,
            'ended_at' => now(),
            'metadata' => $metadata,
        ]));

        $conversation = inAccount($this->account, fn () => Conversation::find($created->id));

        expect($conversation->status)->toBe(ConversationStatus::Ended)
            ->and($conversation->ended_reason)->toBe(EndedReason::Api)
            ->and($conversation->transcript_status)->toBe(TranscriptStatus::Partial)
            ->and($conversation->ended_at)->not->toBeNull()
            ->and($conversation->metadata)->toBeInstanceOf(stdClass::class)
            ->and(json_encode($conversation->metadata))->toBe('{"a":{"b":1},"empty":{},"list":[]}');
    });

    it('holds an empty metadata object as an object', function () {
        $created = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => $this->config->id]));

        $conversation = inAccount($this->account, fn () => Conversation::find($created->id));

        expect($conversation->metadata)->toBeInstanceOf(stdClass::class)->and(json_encode($conversation->metadata))->toBe('{}');
    });

    it('never serializes the provider references or the callback token', function () {
        $created = inAccount($this->account, fn () => Conversation::factory()->create([
            'avatar_config_id' => $this->config->id,
            'provider_session_ref' => 'sess-1',
            'provider_context_ref' => 'ctx-1',
            'callback_token_hash' => hash('sha256', 'token'),
        ]));

        $serialized = json_encode(Conversation::query()->withoutGlobalScopes()->find($created->id));

        expect($serialized)->not->toContain('sess-1')->and($serialized)->not->toContain('ctx-1')
            ->and($serialized)->not->toContain('callback_token_hash')->and($serialized)->not->toContain('provider_session_ref');
    });

    it('does not let mass assignment set the owner, the state or the provider references', function () {
        $conversation = new Conversation([
            'provider' => 'tavus',
            'account_id' => $this->other->id,
            'status' => 'live',
            'ended_reason' => 'api',
            'provider_session_ref' => 'x',
            'provider_context_ref' => 'x',
            'callback_token_hash' => 'x',
            'transcript_status' => 'ready',
            'system_prompt' => 'kept',
        ]);

        expect($conversation->account_id)->toBeNull()
            ->and($conversation->provider)->toBeNull()
            ->and($conversation->status)->toBe(ConversationStatus::Created)
            ->and($conversation->ended_reason)->toBeNull()
            ->and($conversation->provider_session_ref)->toBeNull()
            ->and($conversation->callback_token_hash)->toBeNull()
            ->and($conversation->transcript_status)->toBe(TranscriptStatus::Pending)
            ->and($conversation->system_prompt)->toBe('kept');
    });

    it('tells terminal states from the others', function () {
        expect(ConversationStatus::Ended->isTerminal())->toBeTrue()
            ->and(ConversationStatus::Failed->isTerminal())->toBeTrue()
            ->and(ConversationStatus::Created->isTerminal())->toBeFalse()
            ->and(ConversationStatus::Connecting->isTerminal())->toBeFalse()
            ->and(ConversationStatus::Live->isTerminal())->toBeFalse();
    });
});

describe('conversation events', function () {
    beforeEach(function () {
        $this->conversationId = conversationRow()['id'];
        DB::table('conversations')->insert(conversationRow(['id' => $this->conversationId]));
    });

    it('has the columns of the design', function () {
        expect(columnsOf('conversation_events'))->toBe([
            'id', 'account_id', 'conversation_id', 'sequence', 'source', 'type', 'role', 'text', 'name', 'data',
            'occurred_at', 'idempotency_key', 'created_at', 'updated_at',
        ]);
    });

    it('accepts an utterance and a lifecycle event', function () {
        DB::table('conversation_events')->insert(eventRow($this->conversationId));
        DB::table('conversation_events')->insert(eventRow($this->conversationId, ['sequence' => 2, 'idempotency_key' => 'k-2', 'type' => 'lifecycle', 'role' => null, 'text' => null, 'name' => 'live', 'data' => '{"a":1}']));

        expect(DB::table('conversation_events')->count())->toBe(2);
    });

    it('keeps the idempotency key and the sequence unique per conversation, not across conversations', function () {
        $second = conversationRow()['id'];
        DB::table('conversations')->insert(conversationRow(['id' => $second]));
        DB::table('conversation_events')->insert(eventRow($this->conversationId));

        refused(fn () => DB::table('conversation_events')->insert(eventRow($this->conversationId, ['sequence' => 2])));
        refused(fn () => DB::table('conversation_events')->insert(eventRow($this->conversationId, ['idempotency_key' => 'k-2'])));

        DB::table('conversation_events')->insert(eventRow($second));
        expect(DB::table('conversation_events')->count())->toBe(2);
    });

    it('rejects a row that breaks a constraint', function (array $overrides) {
        expect(fn () => DB::table('conversation_events')->insert(eventRow($this->conversationId, $overrides)))->toThrow(QueryException::class);
    })->with([
        'unknown source' => [['source' => 'server']],
        'unknown type' => [['type' => 'note']],
        'unknown role' => [['role' => 'system']],
        'unknown lifecycle name' => [['name' => 'paused']],
        'a list as data' => [['data' => '[1]']],
        'a conversation that does not exist' => [['conversation_id' => 'conv_missing']],
    ]);

    it('goes with its conversation', function () {
        DB::table('conversation_events')->insert(eventRow($this->conversationId));

        DB::table('conversations')->where('id', $this->conversationId)->delete();

        expect(DB::table('conversation_events')->count())->toBe(0);
    });

    it('is scoped by account like every other model', function () {
        $event = inAccount($this->account, fn () => ConversationEvent::factory()->create([
            'conversation_id' => $this->conversationId,
            'sequence' => 5,
            'idempotency_key' => 'k-5',
        ]));

        expect($event->account_id)->toBe($this->account->id)
            ->and(inAccount($this->other, fn () => ConversationEvent::query()->count()))->toBe(0)
            ->and(inAccount($this->account, fn () => ConversationEvent::query()->count()))->toBe(1);
    });

    it('does not let mass assignment set anything', function () {
        expect(fn () => new ConversationEvent(['conversation_id' => $this->conversationId, 'sequence' => 9, 'source' => 'provider', 'account_id' => $this->other->id, 'text' => 'x']))
            ->toThrow(MassAssignmentException::class);
    });

    it('reads its data back as an object and its time as a date', function () {
        $created = inAccount($this->account, fn () => ConversationEvent::factory()->create([
            'conversation_id' => $this->conversationId,
            'type' => 'lifecycle',
            'role' => null,
            'text' => null,
            'name' => 'error',
            'data' => ['detail' => ['code' => 7]],
            'occurred_at' => now(),
        ]));

        $event = inAccount($this->account, fn () => ConversationEvent::find($created->id));

        expect($event->data)->toBe(['detail' => ['code' => 7]])->and($event->occurred_at)->not->toBeNull();
    });
});
