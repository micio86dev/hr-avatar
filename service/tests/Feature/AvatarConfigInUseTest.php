<?php

use App\Conversations\ConversationStatus;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['avatar_configs:write', 'avatar_configs:read', 'conversations:write', 'conversations:read']]);
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
});

function inUseDelete(AvatarConfig $config): TestResponse
{
    return test()->deleteJson("/v1/avatar-configs/{$config->id}", [], ['Authorization' => 'Bearer '.test()->raw]);
}

function inUseConversation(AvatarConfig $config, ConversationStatus $status): Conversation
{
    return inAccount(test()->account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id, 'status' => $status]));
}

describe('deleting a config', function () {
    it('is refused while a conversation that has not finished uses it', function (ConversationStatus $status) {
        inUseConversation($this->config, $status);

        $response = inUseDelete($this->config)->assertStatus(409);

        expect($response->json('code'))->toBe('config_in_use')
            ->and(inAccount($this->account, fn () => AvatarConfig::find($this->config->id)))->not->toBeNull();
    })->with([ConversationStatus::Created, ConversationStatus::Connecting, ConversationStatus::Live]);

    it('is allowed once every conversation that used it has finished', function (ConversationStatus $status) {
        $conversation = inUseConversation($this->config, $status);

        inUseDelete($this->config)->assertNoContent();

        expect(inAccount($this->account, fn () => AvatarConfig::find($this->config->id)))->toBeNull()
            ->and(inAccount($this->account, fn () => AvatarConfig::withTrashed()->find($this->config->id))->deleted_at)->not->toBeNull()
            ->and(inAccount($this->account, fn () => Conversation::find($conversation->id))->avatar_config_id)->toBe($this->config->id);
    })->with([ConversationStatus::Ended, ConversationStatus::Failed]);

    it('is refused when only one of several conversations has not finished', function () {
        inUseConversation($this->config, ConversationStatus::Ended);
        inUseConversation($this->config, ConversationStatus::Failed);
        inUseConversation($this->config, ConversationStatus::Live);

        inUseDelete($this->config)->assertStatus(409);
    });

    it('is not blocked by the conversations of another config', function () {
        $other = inAccount($this->account, fn () => AvatarConfig::factory()->create());
        inUseConversation($other, ConversationStatus::Live);

        inUseDelete($this->config)->assertNoContent();
    });

    it('keeps the answer a 404 for a config of another account, even one in use', function () {
        $foreignAccount = Account::factory()->create();
        $foreign = inAccount($foreignAccount, fn () => AvatarConfig::factory()->create());
        inAccount($foreignAccount, fn () => Conversation::factory()->create(['avatar_config_id' => $foreign->id, 'status' => ConversationStatus::Live]));

        inUseDelete($foreign)->assertNotFound();
    });
});

describe('the race with a create', function () {
    // One connection cannot interleave two requests, so this pins the mechanism: the config row is locked
    // before the conversations are looked at, inside the transaction that deletes it.
    it('locks the config row before it looks for open conversations', function () {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        inUseDelete($this->config)->assertNoContent();

        $lock = array_find_key($queries, fn (string $sql) => str_contains($sql, 'from "avatar_configs"') && str_contains($sql, 'for update'));
        $look = array_find_key($queries, fn (string $sql) => str_contains($sql, 'from "conversations"'));
        expect($lock)->not->toBeNull()->and($look)->not->toBeNull()->and($lock)->toBeLessThan($look);
    });
});

describe('a deleted config', function () {
    it('cannot start a conversation, and the conversations that used it keep their reference and stay readable', function () {
        $conversation = inUseConversation($this->config, ConversationStatus::Ended);
        inUseDelete($this->config)->assertNoContent();

        $this->postJson('/v1/conversations', ['avatar_config_id' => $this->config->id, 'system_prompt' => 'Hi', 'language' => 'en'], ['Authorization' => 'Bearer '.$this->raw])->assertNotFound();
        $this->getJson("/v1/conversations/{$conversation->id}", ['Authorization' => 'Bearer '.$this->raw])->assertOk()->assertJsonPath('avatar_config_id', $this->config->id);
    });
});
