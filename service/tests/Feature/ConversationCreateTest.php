<?php

use App\AvatarProviders\SyncStatus;
use App\Keys\ClientSecret;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ProviderCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const CONVERSATION_MEMBERS = ['id', 'status', 'avatar_config_id', 'provider', 'language', 'max_duration_seconds', 'external_ref', 'metadata', 'ended_reason', 'transcript_status', 'created_at', 'connecting_at', 'live_at', 'ended_at'];

beforeEach(function () {
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['conversations:write', 'conversations:read']]);
    [, $this->readOnly] = issueKey($this->account, ['scopes' => ['conversations:read']]);
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function convBody(array $overrides = []): array
{
    return ['avatar_config_id' => test()->config->id, 'system_prompt' => 'You greet visitors.', 'language' => 'en', ...$overrides];
}

/**
 * @param  array<string, mixed>  $body
 */
function convCreate(array $body, ?string $raw = null): TestResponse
{
    return test()->postJson('/v1/conversations', $body, ['Authorization' => 'Bearer '.($raw ?? test()->raw)]);
}

function convCount(): int
{
    return Conversation::withoutGlobalScopes()->count();
}

describe('POST /v1/conversations', function () {
    it('creates a conversation in created, with a one-time client secret and no provider call', function () {
        Http::fake();

        $response = convCreate(convBody(['opening_text' => 'Hello!', 'external_ref' => 'order-9']))->assertCreated();

        expect(array_keys($response->json()))->toBe([...CONVERSATION_MEMBERS, 'client_secret', 'client_secret_expires_at'])
            ->and($response->json('id'))->toMatch('/^conv_[0-9a-z]{26}$/')
            ->and($response->json('status'))->toBe('created')
            ->and($response->json('avatar_config_id'))->toBe($this->config->id)
            ->and($response->json('provider'))->toBe('mock')
            ->and($response->json('language'))->toBe('en')
            ->and($response->json('external_ref'))->toBe('order-9')
            ->and($response->json('ended_reason'))->toBeNull()
            ->and($response->json('transcript_status'))->toBe('pending')
            ->and($response->json('connecting_at'))->toBeNull()
            ->and($response->json('live_at'))->toBeNull()
            ->and($response->json('ended_at'))->toBeNull()
            ->and($response->json('created_at'))->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/')
            ->and($response->headers->get('Content-Type'))->toContain('application/json');
        Http::assertNothingSent();

        $stored = Conversation::withoutGlobalScopes()->find($response->json('id'));
        expect($stored->account_id)->toBe($this->account->id)
            ->and($stored->system_prompt)->toBe('You greet visitors.')
            ->and($stored->opening_text)->toBe('Hello!')
            ->and($stored->provider_session_ref)->toBeNull()
            ->and($stored->callback_token_hash)->toBeNull();
    });

    it('mints a client secret for this conversation and account, valid until the connect window and the duration are over', function () {
        $response = convCreate(convBody(['max_duration_seconds' => 600]))->assertCreated();

        $verified = app(ClientSecret::class)->parse($response->json('client_secret'));
        $created = Conversation::withoutGlobalScopes()->find($response->json('id'))->created_at;

        expect($verified)->not->toBeNull()
            ->and($verified->conversationId)->toBe($response->json('id'))
            ->and($verified->accountId)->toBe($this->account->id)
            ->and($verified->expiresAt->equalTo($created->copy()->addSeconds(900 + 600)))->toBeTrue()
            ->and($response->json('client_secret_expires_at'))->toBe($created->copy()->addSeconds(1500)->toIso8601ZuluString());
    });

    it('never stores or logs the client secret, and does not return it again', function () {
        $logs = captureLogs();

        $response = convCreate(convBody())->assertCreated();
        $secret = $response->json('client_secret');

        expect(json_encode(Conversation::withoutGlobalScopes()->find($response->json('id'))->getAttributes()))->not->toContain($secret)
            ->and(implode("\n", $logs->getArrayCopy()))->not->toContain($secret);
        $this->getJson('/v1/conversations/'.$response->json('id'), ['Authorization' => 'Bearer '.$this->raw])->assertOk()->assertJsonMissingPath('client_secret');
    });

    it('accepts a config that is synced or needs no sync, with no credential stored for its provider', function (SyncStatus $status) {
        Http::fake();
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'sync_status' => $status]));

        convCreate(convBody(['avatar_config_id' => $config->id]))->assertCreated()->assertJsonPath('provider', 'tavus');

        expect(ProviderCredential::withoutGlobalScopes()->count())->toBe(0);
        Http::assertNothingSent();
    })->with([SyncStatus::Synced, SyncStatus::NotRequired]);

    it('refuses a config that is pending or failed, and creates nothing', function (SyncStatus $status) {
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create(['provider' => 'tavus', 'config' => ['replica_id' => 'r', 'persona_id' => 'p'], 'sync_status' => $status]));

        $response = convCreate(convBody(['avatar_config_id' => $config->id]))->assertStatus(409);

        expect($response->json('code'))->toBe('config_not_synced')->and(convCount())->toBe(0);
    })->with([SyncStatus::Pending, SyncStatus::Failed]);

    it('answers 404 for a config of another account, a deleted config and an id that is none', function (string $which) {
        $foreign = inAccount(Account::factory()->create(), fn () => AvatarConfig::factory()->create());
        $deleted = inAccount($this->account, fn () => AvatarConfig::factory()->create());
        inAccount($this->account, fn () => $deleted->delete());
        $id = ['foreign' => $foreign->id, 'deleted' => $deleted->id, 'unknown' => 'avc_01j0000000000000000000000x', 'malformed' => 'not an id'][$which];

        $response = convCreate(convBody(['avatar_config_id' => $id]))->assertNotFound();

        expect($response->json('code'))->toBe('not_found')->and(convCount())->toBe(0);
    })->with(['foreign', 'deleted', 'unknown', 'malformed']);

    it('ignores what the host may not choose', function () {
        $other = Account::factory()->create();

        $response = convCreate(convBody([
            'account_id' => $other->id, 'id' => 'conv_chosen', 'status' => 'live', 'provider' => 'tavus',
            'ended_reason' => 'api', 'provider_session_ref' => 'x', 'transcript_status' => 'ready', 'callback_token_hash' => 'x',
        ]))->assertCreated();

        $stored = Conversation::withoutGlobalScopes()->find($response->json('id'));
        expect($stored->account_id)->toBe($this->account->id)
            ->and($stored->id)->not->toBe('conv_chosen')
            ->and($stored->status->value)->toBe('created')
            ->and($stored->provider)->toBe('mock')
            ->and($stored->ended_reason)->toBeNull()
            ->and($stored->provider_session_ref)->toBeNull()
            ->and($stored->transcript_status->value)->toBe('pending');
    });

    it('locks the config row while it checks the config and creates the conversation, so a delete cannot slip between', function () {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        convCreate(convBody())->assertCreated();

        $lock = array_find_key($queries, fn (string $sql) => str_contains($sql, 'from "avatar_configs"') && str_contains($sql, 'for update'));
        $insert = array_find_key($queries, fn (string $sql) => str_contains($sql, 'insert into "conversations"'));
        expect($lock)->not->toBeNull()->and($insert)->not->toBeNull()->and($lock)->toBeLessThan($insert);
    });

    it('needs the write scope and a key', function () {
        convCreate(convBody(), $this->readOnly)->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
        $this->postJson('/v1/conversations', convBody())->assertStatus(401);
        expect(convCount())->toBe(0);
    });
});

describe('duration', function () {
    it('defaults to 1800 seconds', function () {
        convCreate(convBody())->assertCreated()->assertJsonPath('max_duration_seconds', 1800);
    });

    it('takes a value between 30 and the ceiling', function (int $seconds) {
        convCreate(convBody(['max_duration_seconds' => $seconds]))->assertCreated()->assertJsonPath('max_duration_seconds', $seconds);
    })->with([30, 31, 1800, 3600]);

    it('refuses a value outside it, naming the field', function (mixed $seconds) {
        $response = convCreate(convBody(['max_duration_seconds' => $seconds]))->assertStatus(422);

        expect($response->json('code'))->toBe('validation_failed')->and($response->json('errors'))->toHaveKey('max_duration_seconds')->and(convCount())->toBe(0);
    })->with([29, 0, -5, 3601, 'soon', 60.5, 'a list' => [[60]]]);

    it('follows the configured ceiling, and a default above it comes down to it', function () {
        config()->set('hr-avatar.max_duration_ceiling', 900);

        convCreate(convBody())->assertCreated()->assertJsonPath('max_duration_seconds', 900);
        convCreate(convBody(['max_duration_seconds' => 900]))->assertCreated();
        convCreate(convBody(['max_duration_seconds' => 901]))->assertStatus(422);
    });

    it('reads null as not given', function () {
        convCreate(convBody(['max_duration_seconds' => null]))->assertCreated()->assertJsonPath('max_duration_seconds', 1800);
    });
});

describe('validation', function () {
    it('refuses a body that breaks a rule, names the field and creates nothing', function (string $field, array $overrides) {
        $response = convCreate(convBody($overrides))->assertStatus(422);

        expect($response->json('code'))->toBe('validation_failed')->and(array_keys($response->json('errors')))->toContain($field)->and(convCount())->toBe(0);
    })->with([
        'no system prompt' => ['system_prompt', ['system_prompt' => null]],
        'empty system prompt' => ['system_prompt', ['system_prompt' => '']],
        'system prompt too long' => ['system_prompt', ['system_prompt' => str_repeat('a', 32001)]],
        'system prompt not text' => ['system_prompt', ['system_prompt' => ['a']]],
        'no language' => ['language', ['language' => null]],
        'language in capitals' => ['language', ['language' => 'EN']],
        'language too long' => ['language', ['language' => 'eng']],
        'language too short' => ['language', ['language' => 'e']],
        'language with a digit' => ['language', ['language' => 'e1']],
        'language with a space' => ['language', ['language' => 'e n']],
        'opening text too long' => ['opening_text', ['opening_text' => str_repeat('a', 4001)]],
        'external ref too long' => ['external_ref', ['external_ref' => str_repeat('a', 256)]],
        'external ref not text' => ['external_ref', ['external_ref' => ['a']]],
        'no config' => ['avatar_config_id', ['avatar_config_id' => null]],
        'config not text' => ['avatar_config_id', ['avatar_config_id' => ['avc_x']]],
    ]);

    it('accepts the limits', function () {
        convCreate(convBody(['system_prompt' => str_repeat('a', 32000), 'opening_text' => str_repeat('é', 4000), 'external_ref' => str_repeat('r', 255)]))->assertCreated();
    });

    it('keeps the whitespace of the host\'s own text: nothing is trimmed or turned into null', function () {
        $response = convCreate(convBody(['system_prompt' => "  Be brief.\n", 'opening_text' => ' Hello ', 'external_ref' => ' order-9 ']))->assertCreated();

        $stored = Conversation::withoutGlobalScopes()->find($response->json('id'));
        expect($response->json('external_ref'))->toBe(' order-9 ')
            ->and($stored->system_prompt)->toBe("  Be brief.\n")
            ->and($stored->opening_text)->toBe(' Hello ');
    });

    it('treats an absent or null opening text and external ref as none', function () {
        $response = convCreate(convBody(['opening_text' => null, 'external_ref' => null]))->assertCreated();

        expect($response->json('external_ref'))->toBeNull()
            ->and(Conversation::withoutGlobalScopes()->find($response->json('id'))->opening_text)->toBeNull();
    });

    it('reports every problem at once', function () {
        $response = convCreate(['avatar_config_id' => $this->config->id])->assertStatus(422);

        expect(array_keys($response->json('errors')))->toBe(['system_prompt', 'language']);
    });

    it('answers a validation error before looking for the config', function () {
        convCreate(convBody(['avatar_config_id' => 'avc_01j0000000000000000000000x', 'language' => 'xx1']))->assertStatus(422);
    });
});

describe('the host\'s opaque fields', function () {
    it('round-trips an object as it was sent: key order, nesting, empty objects and lists', function () {
        $raw = '{"z":1,"a":{"y":[],"x":{},"w":[1,{"v":null}]},"é":"ü","n":1.5,"t":true}';

        $response = convCreate(convBody(['external_ref' => 'order-9', 'metadata' => json_decode($raw, false)]))->assertCreated();

        expect($response->getContent())->toContain('"metadata":'.json_encode(json_decode($raw, false)));
        $show = $this->getJson('/v1/conversations/'.$response->json('id'), ['Authorization' => 'Bearer '.$this->raw])->assertOk();
        expect($show->getContent())->toContain('"metadata":'.json_encode(json_decode($raw, false)))->and($show->json('external_ref'))->toBe('order-9');
    });

    it('keeps an empty object an object, and answers one when none was sent', function (array $overrides) {
        $response = convCreate(convBody($overrides))->assertCreated();

        expect($response->getContent())->toContain('"metadata":{}');
    })->with([
        'sent empty' => [['metadata' => new stdClass]],
        'not sent' => [[]],
        'null' => [['metadata' => null]],
    ]);

    it('refuses a metadata that is not an object', function (mixed $metadata) {
        $response = convCreate(convBody(['metadata' => $metadata]))->assertStatus(422);

        expect($response->json('errors'))->toHaveKey('metadata')->and(convCount())->toBe(0);
    })->with(['a list' => [[1, 2]], 'an empty list' => [[]], 'a string' => ['text'], 'a number' => [7], 'a boolean' => [true]]);

    it('refuses a metadata of more than 8 KB and accepts exactly 8 KB', function () {
        $overhead = strlen(json_encode(['k' => '']));
        $exact = ['k' => str_repeat('a', 8192 - $overhead)];
        expect(strlen(json_encode($exact)))->toBe(8192);

        convCreate(convBody(['metadata' => (object) $exact]))->assertCreated();
        $response = convCreate(convBody(['metadata' => (object) ['k' => str_repeat('a', 8193 - $overhead)]]))->assertStatus(422);

        expect($response->json('errors'))->toHaveKey('metadata');
    });

    it('counts the size in bytes, not characters', function () {
        $overhead = strlen(json_encode(['k' => ''], JSON_UNESCAPED_UNICODE));

        convCreate(convBody(['metadata' => (object) ['k' => str_repeat('é', intdiv(8192 - $overhead, 2))]]))->assertCreated();
        convCreate(convBody(['metadata' => (object) ['k' => str_repeat('é', intdiv(8192 - $overhead, 2) + 1)]]))->assertStatus(422);
    });

    it('does not accept metadata sent as form fields', function () {
        $this->post('/v1/conversations', convBody(['metadata' => ['a' => 1]]), ['Authorization' => 'Bearer '.$this->raw, 'Accept' => 'application/json'])
            ->assertStatus(422);
    });

    it('refuses a body that is not JSON text at all with the usual errors', function () {
        $this->call('POST', '/v1/conversations', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->raw], '{not json')
            ->assertStatus(422);
    });
});
