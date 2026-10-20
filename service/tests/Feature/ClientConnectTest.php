<?php

use App\AvatarProviders\Ingestion;
use App\AvatarProviders\ProviderException;
use App\Conversations\ConnectConversation;
use App\Conversations\ConversationLifecycle;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptStatus;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Jobs\ReleaseProviderSession;
use App\Keys\ClientSecret;
use App\Keys\KeyType;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\LlmModel;
use App\Models\ProviderCredential;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\AvatarProviders\ScriptedProvider;

beforeEach(function () {
    Queue::fake();
    $this->account = Account::factory()->create();
    [, $this->pk] = issueKey($this->account, type: KeyType::Publishable);
    $this->config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    inAccount($this->account, fn () => WebhookEndpoint::factory()->create());
});

/**
 * A `created` conversation of the test account. Mock unless told otherwise.
 *
 * @param  array<string, mixed>  $attributes
 */
function connectable(array $attributes = [], ?AvatarConfig $config = null): Conversation
{
    return inAccount(test()->account, fn () => Conversation::factory()->create([
        'avatar_config_id' => ($config ?? test()->config)->id,
        'provider' => ($config ?? test()->config)->provider,
        ...$attributes,
    ]));
}

function scriptedConfig(?string $llmModelId = null): AvatarConfig
{
    ScriptedProvider::register();

    return inAccount(test()->account, fn () => AvatarConfig::factory()->create(['provider' => 'scripted', 'llm_model_id' => $llmModelId]));
}

/**
 * @param  array<string, string>  $headers  replaces the browser's own headers when given
 */
function connectCall(Conversation $conversation, ?string $secret = null, ?array $headers = null): TestResponse
{
    return test()->postJson(
        "/v1/client/conversations/{$conversation->id}/connect",
        [],
        $headers ?? browserHeaders(test()->pk, $secret ?? clientSecretFor($conversation)),
    );
}

function storedConversation(Conversation $conversation): Conversation
{
    return Conversation::withoutGlobalScopes()->findOrFail($conversation->id);
}

/**
 * @return list<string>
 */
function connectDeliveries(Conversation $conversation): array
{
    return WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('created_at')->orderBy('id')->get()
        ->map(fn (WebhookDelivery $delivery) => $delivery->event_type->value)->all();
}

/**
 * The code of the problem a call raises, null when it raises none.
 */
function connectProblem(Closure $callback): ?ErrorCode
{
    try {
        $callback();
    } catch (ApiProblem $problem) {
        return $problem->errorCode;
    }

    return null;
}

function startCalls(): int
{
    return count(array_filter(ScriptedProvider::$calls, fn (array $call) => $call[0] === 'start'));
}

describe('connecting a conversation on the Mock provider', function () {
    it('answers 200 with the join material and moves the conversation to connecting', function () {
        $conversation = connectable();

        $response = connectCall($conversation)->assertOk();

        expect(array_keys($response->json()))->toBe(['provider', 'transport', 'conversation_url', 'session_token', 'expires_at'])
            ->and($response->json('provider'))->toBe('mock')
            ->and($response->json('transport'))->toBe('mock')
            ->and($response->json('conversation_url'))->toBe("mock://conversation/{$conversation->id}")
            ->and($response->json('session_token'))->toBe("mock_token_{$conversation->id}")
            ->and($response->json('expires_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/');
        $stored = storedConversation($conversation);
        expect($stored->status)->toBe(ConversationStatus::Connecting)
            ->and($stored->connecting_at?->toIso8601String())->toBe(now()->toIso8601String())
            ->and($stored->provider_session_ref)->toBe("mock_{$conversation->id}");
    });

    it('lets the browser read the answer: the allowed origin is echoed', function () {
        $response = connectCall(connectable())->assertOk();

        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.com');
    });

    it('answers a preflight for the route without any credential', function () {
        $conversation = connectable();

        $this->call('OPTIONS', "/v1/client/conversations/{$conversation->id}/connect", [], [], [], $this->transformHeadersToServerVars([
            'Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'POST',
        ]))->assertNoContent();
        expect(storedConversation($conversation)->status)->toBe(ConversationStatus::Created);
    });

    it('holds no provider reference in what it returns, and takes nothing from the body', function () {
        $conversation = connectable();

        $response = $this->postJson("/v1/client/conversations/{$conversation->id}/connect", ['status' => 'live', 'provider_session_ref' => 'forged', 'account_id' => 'x'], browserHeaders($this->pk, clientSecretFor($conversation)))->assertOk();

        expect($response->getContent())->not->toContain('forged')->and($response->getContent())->not->toContain('provider_session_ref')
            ->and(storedConversation($conversation)->status)->toBe(ConversationStatus::Connecting)
            ->and(storedConversation($conversation)->provider_session_ref)->toBe("mock_{$conversation->id}");
    });
});

describe('connecting twice', function () {
    it('answers 409 already_connected for a conversation that is connecting or live, and starts nothing', function (ConversationStatus $status) {
        $config = scriptedConfig();
        $conversation = connectable(['status' => $status, 'connecting_at' => now()->subMinute()], $config);
        $before = storedConversation($conversation)->getAttributes();

        $response = connectCall($conversation)->assertStatus(409);

        expect($response->json('code'))->toBe('already_connected')
            ->and(storedConversation($conversation)->getAttributes())->toBe($before)
            ->and(startCalls())->toBe(0);
    })->with([ConversationStatus::Connecting, ConversationStatus::Live]);

    it('lets the first of two connects win and runs start once', function () {
        $config = scriptedConfig();
        $conversation = connectable([], $config);
        $first = Conversation::withoutGlobalScopes()->findOrFail($conversation->id);
        $second = Conversation::withoutGlobalScopes()->findOrFail($conversation->id);

        $join = app(ConnectConversation::class)->connect($first);
        $lost = connectProblem(fn () => app(ConnectConversation::class)->connect($second));

        expect($join->provider)->toBe('scripted')
            ->and($lost)->toBe(ErrorCode::AlreadyConnected)
            ->and(startCalls())->toBe(1)
            ->and(storedConversation($conversation)->status)->toBe(ConversationStatus::Connecting);
    });

    it('answers 409 invalid_state for a conversation that is over', function (ConversationStatus $status) {
        $config = scriptedConfig();
        $conversation = connectable([
            'status' => $status, 'connecting_at' => now()->subMinutes(5), 'ended_at' => now()->subMinute(),
            'ended_reason' => $status === ConversationStatus::Ended ? EndedReason::Client : EndedReason::ProviderError,
        ], $config);
        $before = storedConversation($conversation)->getAttributes();

        $response = connectCall($conversation)->assertStatus(409);

        expect($response->json('code'))->toBe('invalid_state')
            ->and(storedConversation($conversation)->getAttributes())->toBe($before)
            ->and(startCalls())->toBe(0);
    })->with([ConversationStatus::Ended, ConversationStatus::Failed]);

    it('answers 409 invalid_state when the conversation ended between the read and the compare-and-set', function () {
        $config = scriptedConfig();
        $conversation = connectable([], $config);
        $stale = Conversation::withoutGlobalScopes()->findOrFail($conversation->id);
        app(ConversationLifecycle::class)->end(Conversation::withoutGlobalScopes()->findOrFail($conversation->id), EndedReason::Api);

        $refused = connectProblem(fn () => app(ConnectConversation::class)->connect($stale));

        expect($refused)->toBe(ErrorCode::InvalidState)->and(startCalls())->toBe(0);
    });
});

describe('the credential pre-checks', function () {
    it('answers 422 provider_credential_missing and leaves the conversation exactly as it was', function () {
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create(['provider' => 'tavus', 'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1']]));
        $conversation = connectable([], $config);
        $before = storedConversation($conversation)->getAttributes();

        $response = connectCall($conversation)->assertStatus(422);

        expect($response->json('code'))->toBe('provider_credential_missing')
            ->and(storedConversation($conversation)->getAttributes())->toBe($before)
            ->and(connectDeliveries($conversation))->toBe([]);
        Http::assertNothingSent();
    });

    it('accepts the platform\'s key when the account has none', function () {
        config()->set('hr-avatar.platform_keys.tavus', 'platform-key-9');
        Http::fake(['tavusapi.com/*' => Http::response(['conversation_id' => 'c123', 'conversation_url' => 'https://tavus.daily.co/c123', 'status' => 'active'])]);
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create(['provider' => 'tavus', 'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1']]));
        $conversation = connectable([], $config);

        connectCall($conversation)->assertOk()->assertJsonPath('provider', 'tavus');
        Http::assertSent(fn ($request) => $request->header('x-api-key') === ['platform-key-9']);
    });

    it('answers 422 llm_credential_missing for a bound model with no key, and stays created', function () {
        inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'heygen', 'api_key' => 'la-key-123456']));
        LlmModel::factory()->create(['id' => 'gem-1', 'provider' => 'gemini', 'model' => 'gemini-x']);
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create(['provider' => 'heygen', 'llm_model_id' => 'gem-1', 'config' => ['avatar_id' => 'a_1']]));
        $conversation = connectable([], $config);
        $before = storedConversation($conversation)->getAttributes();

        $response = connectCall($conversation)->assertStatus(422);

        expect($response->json('code'))->toBe('llm_credential_missing')
            ->and(storedConversation($conversation)->getAttributes())->toBe($before);
        Http::assertNothingSent();
    });

    it('checks the provider\'s credential before the model\'s', function () {
        LlmModel::factory()->create(['id' => 'gem-1', 'provider' => 'gemini', 'model' => 'gemini-x']);
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create(['provider' => 'heygen', 'llm_model_id' => 'gem-1', 'config' => ['avatar_id' => 'a_1']]));

        $response = connectCall(connectable([], $config))->assertStatus(422);

        expect($response->json('code'))->toBe('provider_credential_missing');
    });

    it('skips both checks for a provider that takes no credential, even with a model bound', function () {
        LlmModel::factory()->create(['id' => 'gem-1', 'provider' => 'gemini', 'model' => 'gemini-x']);
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create(['provider' => 'mock', 'llm_model_id' => 'gem-1']));

        connectCall(connectable([], $config))->assertOk();
    });
});

describe('starting the provider session', function () {
    it('gives the adapter the conversation, its settings and the credential, and no callback target when it takes none', function () {
        $config = scriptedConfig();
        $conversation = connectable(['system_prompt' => 'Be kind.', 'opening_text' => 'Hello!', 'language' => 'it', 'max_duration_seconds' => 600, 'metadata' => ['a' => ['b' => 1]]], $config);

        connectCall($conversation)->assertOk();

        expect(ScriptedProvider::$calls)->toBe([['start', $conversation->id, null, 'none']])
            ->and(ScriptedProvider::$lastSpec?->systemPrompt)->toBe('Be kind.')
            ->and(ScriptedProvider::$lastSpec?->openingText)->toBe('Hello!')
            ->and(ScriptedProvider::$lastSpec?->language)->toBe('it')
            ->and(ScriptedProvider::$lastSpec?->maxDurationSeconds)->toBe(600)
            ->and(json_encode(ScriptedProvider::$lastSpec?->metadata))->toBe('{"a":{"b":1}}');
    });

    it('runs outside any database transaction of its own', function () {
        $config = scriptedConfig();
        $baseline = DB::transactionLevel();
        $level = null;
        ScriptedProvider::$starts = [function () use (&$level) {
            $level = DB::transactionLevel();

            return null;
        }];

        connectCall(connectable([], $config))->assertOk();

        expect($level)->toBe($baseline);
    });

    it('keeps the provider\'s references on the conversation and returns the browser\'s side only', function () {
        $config = scriptedConfig();
        $conversation = connectable([], $config);

        $response = connectCall($conversation)->assertOk();

        $stored = storedConversation($conversation);
        expect($stored->provider_session_ref)->toBe("scripted_{$conversation->id}")
            ->and($stored->provider_context_ref)->toBe("context_{$conversation->id}")
            ->and($response->getContent())->not->toContain("context_{$conversation->id}")
            ->and($response->getContent())->not->toContain("scripted_{$conversation->id}")
            ->and($response->json('session_token'))->toBe('join-token');
    });

    it('does not announce a conversation that is only connecting', function () {
        $config = scriptedConfig();
        $conversation = connectable([], $config);

        connectCall($conversation)->assertOk();

        expect(connectDeliveries($conversation))->toBe([]);
        Queue::assertNothingPushed();
    });
});

describe('a start that fails', function () {
    it('fails the conversation with provider_error and answers 502', function () {
        $config = scriptedConfig();
        ScriptedProvider::$starts = [fn () => throw ProviderException::fromStatus(500)];
        $conversation = connectable([], $config);

        $response = connectCall($conversation)->assertStatus(502);

        $stored = storedConversation($conversation);
        expect($response->json('code'))->toBe('provider_error')
            ->and($stored->status)->toBe(ConversationStatus::Failed)
            ->and($stored->ended_reason)->toBe(EndedReason::ProviderError)
            ->and($stored->ended_at)->not->toBeNull()
            ->and($stored->connecting_at)->not->toBeNull()
            ->and($stored->provider_session_ref)->toBeNull()
            ->and($stored->transcript_status)->toBe(TranscriptStatus::Ready)
            ->and(connectDeliveries($conversation))->toBe(['conversation.ended', 'transcript.ready']);
        Queue::assertNotPushed(ReleaseProviderSession::class);
    });

    it('reports the failure as conversation.ended with the status failed', function () {
        $config = scriptedConfig();
        ScriptedProvider::$starts = [fn () => throw ProviderException::noResponse()];
        $conversation = connectable([], $config);

        connectCall($conversation)->assertStatus(502);

        $delivery = WebhookDelivery::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('event_type', 'conversation.ended')->firstOrFail();
        expect(json_decode($delivery->payload, true)['data']['conversation'])->toMatchArray(['status' => 'failed', 'ended_reason' => 'provider_error']);
    });

    it('fails the conversation with provider_capacity and answers 429 when the provider is at capacity', function (int $status, bool $otherThan429) {
        $config = scriptedConfig();
        ScriptedProvider::$starts = [fn () => throw ($otherThan429 ? ProviderException::throttled($status) : ProviderException::fromStatus($status))];
        $conversation = connectable([], $config);

        $response = connectCall($conversation)->assertStatus(429);

        expect($response->json('code'))->toBe('provider_concurrency_limited')
            ->and(storedConversation($conversation)->status)->toBe(ConversationStatus::Failed)
            ->and(storedConversation($conversation)->ended_reason)->toBe(EndedReason::ProviderCapacity);
    })->with([
        'a 429' => [429, false],
        'a capacity limit under another status' => [503, true],
    ]);

    it('does not repeat what the provider said', function () {
        $config = scriptedConfig();
        ScriptedProvider::$starts = [fn () => throw ProviderException::fromStatus(500)];

        $response = connectCall(connectable([], $config))->assertStatus(502);

        expect($response->getContent())->not->toContain('500');
    });

    it('fails the conversation too when something unexpected goes wrong, rather than leaving it connecting', function () {
        $config = scriptedConfig();
        ScriptedProvider::$starts = [fn () => throw new RuntimeException('boom with a secret')];
        $conversation = connectable([], $config);

        $response = connectCall($conversation)->assertStatus(500);

        expect($response->getContent())->not->toContain('boom')
            ->and(storedConversation($conversation)->status)->toBe(ConversationStatus::Failed)
            ->and(storedConversation($conversation)->ended_reason)->toBe(EndedReason::ProviderError);
    });

    it('lets a conversation that was ended in the meantime stay ended', function () {
        $config = scriptedConfig();
        $conversation = connectable([], $config);
        ScriptedProvider::$starts = [function () use ($conversation) {
            app(ConversationLifecycle::class)->end(Conversation::withoutGlobalScopes()->findOrFail($conversation->id), EndedReason::Client);

            throw ProviderException::fromStatus(500);
        }];

        connectCall($conversation)->assertStatus(502);

        expect(storedConversation($conversation)->status)->toBe(ConversationStatus::Ended)
            ->and(storedConversation($conversation)->ended_reason)->toBe(EndedReason::Client);
    });
});

describe('anything that goes wrong once the conversation is claimed', function () {
    // The claim (`created -> connecting`) is a point of no return for a retry: a second connect answers 409
    // already_connected. Whatever fails after it must therefore end the conversation, never leave it connecting.
    it('fails the conversation when the callback target cannot be prepared, and starts nothing', function () {
        $config = scriptedConfig();
        ScriptedProvider::$ingestion = Ingestion::Callback;
        $conversation = connectable([], $config);
        DB::listen(function (QueryExecuted $query) {
            if (str_contains($query->sql, 'callback_token_hash')) {
                throw new RuntimeException('database gone: password=hunter2');
            }
        });

        $response = connectCall($conversation)->assertStatus(500);

        expect($response->getContent())->not->toContain('hunter2')
            ->and(storedConversation($conversation)->status)->toBe(ConversationStatus::Failed)
            ->and(storedConversation($conversation)->ended_reason)->toBe(EndedReason::ProviderError)
            ->and(startCalls())->toBe(0);
        expect(connectDeliveries($conversation))->toBe(['conversation.ended', 'transcript.ready']);
    });

    it('fails the conversation and ends the session when the provider\'s references cannot be kept', function () {
        $config = scriptedConfig();
        $conversation = connectable([], $config);
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'update "conversations" set "provider_session_ref"')) {
                throw new RuntimeException('database gone');
            }
        });

        connectCall($conversation)->assertStatus(500);

        expect(storedConversation($conversation)->status)->toBe(ConversationStatus::Failed)
            ->and(storedConversation($conversation)->ended_reason)->toBe(EndedReason::ProviderError)
            ->and(array_values(array_filter(ScriptedProvider::$calls, fn (array $call) => $call[0] === 'teardown')))
            ->toBe([['teardown', "scripted_{$conversation->id}", "context_{$conversation->id}", ScriptedProvider::$calls[0][3]]]);
    });

    it('ends the session even when failing the conversation throws', function () {
        $conversation = connectable([], scriptedConfig());
        DB::listen(function (QueryExecuted $query) {
            // The references cannot be kept, and the write that would fail the conversation breaks as well.
            if (str_starts_with($query->sql, 'update "conversations" set "provider_session_ref"') || (str_starts_with($query->sql, 'update "conversations"') && in_array('failed', $query->bindings, true))) {
                throw new RuntimeException('database gone');
            }
        });

        connectCall($conversation)->assertStatus(500);

        expect(array_values(array_filter(ScriptedProvider::$calls, fn (array $call) => $call[0] === 'teardown')))
            ->toBe([['teardown', "scripted_{$conversation->id}", "context_{$conversation->id}", ScriptedProvider::$calls[0][3]]]);
    });

    it('still fails the conversation when that session cannot be ended either, and logs only the kind of failure', function () {
        $config = scriptedConfig();
        ScriptedProvider::$teardowns = [fn () => throw new RuntimeException('provider said: token=abc123')];
        $conversation = connectable([], $config);
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with($query->sql, 'update "conversations" set "provider_session_ref"')) {
                throw new RuntimeException('database gone');
            }
        });
        $logs = captureLogs();

        connectCall($conversation)->assertStatus(500);

        expect(storedConversation($conversation)->status)->toBe(ConversationStatus::Failed)
            ->and(implode("\n", $logs->getArrayCopy()))->toContain('conversation_id')->not->toContain('abc123');
    });

    it('leaves a conversation of an unknown provider created, so it can be connected once the provider exists', function () {
        $conversation = connectable(['provider' => 'nonexistent']);

        connectCall($conversation)->assertStatus(500)->assertJsonPath('code', 'internal_error');

        expect(storedConversation($conversation)->status)->toBe(ConversationStatus::Created);
    });
});

describe('a conversation that ends while its session is being started', function () {
    it('answers 409 invalid_state and releases the session that was created', function () {
        $config = scriptedConfig();
        $conversation = connectable([], $config);
        ScriptedProvider::$starts = [function () use ($conversation) {
            app(ConversationLifecycle::class)->end(Conversation::withoutGlobalScopes()->findOrFail($conversation->id), EndedReason::Client);

            return null;
        }];

        $response = connectCall($conversation)->assertStatus(409);

        $stored = storedConversation($conversation);
        expect($response->json('code'))->toBe('invalid_state')
            ->and($stored->status)->toBe(ConversationStatus::Ended)
            ->and($stored->provider_session_ref)->toBe("scripted_{$conversation->id}");
        Queue::assertPushed(ReleaseProviderSession::class, fn (ReleaseProviderSession $job) => $job->conversationId === $conversation->id && $job->accountId === $conversation->account_id);
    });
});

describe('a conversation that ends before the provider\'s reference is stored', function () {
    // While `provider_session_ref` is still null the lifecycle has nothing to release, so it queues nothing; the session
    // that the start then creates is released once, by the connect, when it finds the conversation over (never leaked,
    // never released twice).
    it('queues the release once, after the references are kept and not before', function () {
        $config = scriptedConfig();
        $conversation = connectable([], $config);
        $queuedWhileStarting = null;
        ScriptedProvider::$starts = [function () use ($conversation, &$queuedWhileStarting) {
            app(ConversationLifecycle::class)->end(Conversation::withoutGlobalScopes()->findOrFail($conversation->id), EndedReason::Client);
            $queuedWhileStarting = Queue::pushed(ReleaseProviderSession::class)->count();

            return null;
        }];

        connectCall($conversation)->assertStatus(409);

        expect($queuedWhileStarting)->toBe(0)
            ->and(storedConversation($conversation)->provider_session_ref)->not->toBeNull();
        Queue::assertPushed(ReleaseProviderSession::class, 1);
        Queue::assertPushed(ReleaseProviderSession::class, fn (ReleaseProviderSession $job) => $job->delay === config('hr-avatar.release_delay_seconds'));
    });
});

describe('who may connect', function () {
    it('answers 404 for a conversation the key\'s account does not have, even with a valid secret for it', function () {
        $stranger = Account::factory()->create();
        $foreign = inAccount($stranger, fn () => Conversation::factory()->create(['avatar_config_id' => inAccount($stranger, fn () => AvatarConfig::factory()->create())->id]));
        // A secret naming the key's own account but another account's conversation id: only the signing secret could have made it.
        $secret = app(ClientSecret::class)->mint($foreign->id, $this->account->id, now(), 1800)->token;

        $response = connectCall($foreign, $secret)->assertNotFound();

        expect($response->json('code'))->toBe('not_found')
            ->and(storedConversation($foreign)->status)->toBe(ConversationStatus::Created);
    });

    it('answers 403 for a secret that belongs to another conversation', function () {
        $first = connectable();
        $second = connectable();

        $response = connectCall($first, clientSecretFor($second))->assertForbidden();

        expect($response->json('code'))->toBe('forbidden')->and(storedConversation($first)->status)->toBe(ConversationStatus::Created);
    });

    it('answers 401 without a client secret, and 403 from an origin the key does not allow', function () {
        $conversation = connectable();

        $this->postJson("/v1/client/conversations/{$conversation->id}/connect", [], ['X-HRA-Publishable-Key' => $this->pk, 'Origin' => 'https://app.example.com'])->assertUnauthorized();
        connectCall($conversation, headers: browserHeaders($this->pk, clientSecretFor($conversation), 'https://evil.example.com'))->assertForbidden();
        expect(storedConversation($conversation)->status)->toBe(ConversationStatus::Created);
    });

    it('refuses a key in the query string', function () {
        $conversation = connectable();

        $this->postJson("/v1/client/conversations/{$conversation->id}/connect?api_key={$this->pk}", [], browserHeaders($this->pk, clientSecretFor($conversation)))->assertStatus(400);
        expect(storedConversation($conversation)->status)->toBe(ConversationStatus::Created);
    });

    it('is limited by the key\'s publishable budget', function () {
        config()->set('hr-avatar.rate_limits.publishable', 1);

        connectCall(connectable())->assertOk();
        $second = connectCall(connectable())->assertStatus(429);

        expect($second->json('code'))->toBe('rate_limited');
    });
});
