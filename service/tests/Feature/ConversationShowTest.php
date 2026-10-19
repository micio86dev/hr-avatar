<?php

use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
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
function convMake(array $attributes = [], ?Account $account = null, ?AvatarConfig $config = null): Conversation
{
    $account ??= test()->account;

    return inAccount($account, fn () => Conversation::factory()->create(['avatar_config_id' => ($config ?? ($account->is(test()->account) ? test()->config : null))?->id ?? AvatarConfig::factory(), ...$attributes]));
}

function convShow(string $id, ?string $raw = null): TestResponse
{
    return test()->getJson("/v1/conversations/{$id}", ['Authorization' => 'Bearer '.($raw ?? test()->raw)]);
}

describe('GET /v1/conversations/{id}', function () {
    it('shows the documented members of a conversation of the account', function () {
        $conversation = convMake(['external_ref' => 'order-9', 'language' => 'it', 'max_duration_seconds' => 600]);

        $response = convShow($conversation->id)->assertOk();

        expect(array_keys($response->json()))->toBe(CONVERSATION_MEMBERS)
            ->and($response->json('id'))->toBe($conversation->id)
            ->and($response->json('status'))->toBe('created')
            ->and($response->json('avatar_config_id'))->toBe($this->config->id)
            ->and($response->json('provider'))->toBe('mock')
            ->and($response->json('language'))->toBe('it')
            ->and($response->json('max_duration_seconds'))->toBe(600)
            ->and($response->json('external_ref'))->toBe('order-9')
            ->and($response->getContent())->toContain('"metadata":{}');
    });

    it('shows the end of a finished conversation', function () {
        $conversation = convMake([
            'status' => ConversationStatus::Ended, 'ended_reason' => EndedReason::Api, 'connecting_at' => now()->subMinutes(5),
            'live_at' => now()->subMinutes(4), 'ended_at' => now(), 'provider_end_reason' => 'shutdown',
        ]);

        $response = convShow($conversation->id)->assertOk();

        expect($response->json('status'))->toBe('ended')
            ->and($response->json('ended_reason'))->toBe('api')
            ->and($response->json('connecting_at'))->not->toBeNull()
            ->and($response->json('live_at'))->not->toBeNull()
            ->and($response->json('ended_at'))->not->toBeNull()
            ->and($response->getContent())->not->toContain('shutdown');
    });

    it('carries no internals: no provider reference, callback token or hash, secret or prompt', function () {
        $conversation = convMake([
            'status' => ConversationStatus::Connecting, 'provider_session_ref' => 'sess-ref-77', 'provider_context_ref' => 'ctx-ref-88',
            'callback_token_hash' => hash('sha256', 'cb-token'), 'system_prompt' => 'SECRET-PROMPT', 'opening_text' => 'OPENING-TEXT',
        ]);

        $body = convShow($conversation->id)->assertOk()->getContent();

        foreach (['sess-ref-77', 'ctx-ref-88', hash('sha256', 'cb-token'), 'provider_session_ref', 'provider_context_ref', 'callback_token', 'client_secret', 'SECRET-PROMPT', 'OPENING-TEXT', 'system_prompt'] as $internal) {
            expect($body)->not->toContain($internal);
        }
    });

    it('answers 404 for a conversation of another account and for one that does not exist', function () {
        $foreign = convMake([], Account::factory()->create());

        convShow($foreign->id)->assertNotFound()->assertJsonPath('code', 'not_found');
        convShow('conv_01j0000000000000000000000x')->assertNotFound();
        convShow('anything')->assertNotFound();
    });

    it('still shows a conversation whose config was deleted since', function () {
        $conversation = convMake();
        inAccount($this->account, fn () => $this->config->delete());

        convShow($conversation->id)->assertOk()->assertJsonPath('avatar_config_id', $this->config->id);
    });

    it('needs the read scope and a key', function () {
        $conversation = convMake();

        convShow($conversation->id, $this->writeOnly)->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
        $this->getJson("/v1/conversations/{$conversation->id}")->assertStatus(401);
    });
});
