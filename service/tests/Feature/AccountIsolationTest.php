<?php

use App\Conversations\ConversationStatus;
use App\Keys\ClientSecret;
use App\Keys\KeyType;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\ProviderCredential;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * Account B, with a perfectly good key, reaches nothing of account A: through every authenticated route, a
 * resource of A is not found, a list holds none of A's rows, a write lands in B, and A's rows are byte for byte
 * what they were. The dataset is every authenticated route; a route missing from it fails the last test.
 */
const ISOLATED_TABLES = ['api_keys', 'avatar_configs', 'conversations', 'conversation_events', 'webhook_endpoints', 'webhook_deliveries', 'provider_credentials', 'llm_credentials', 'provider_resources', 'provider_webhook_receipts'];

beforeEach(function () {
    Http::preventStrayRequests();
    LlmModel::factory()->create(['id' => 'gem-1', 'provider' => 'gemini', 'model' => 'gemini-x']);
    $this->a = Account::factory()->create();
    $this->b = Account::factory()->create();
    [$this->keyA] = issueKey($this->a, ['name' => 'marker-a-key']);
    [, $this->rawB] = issueKey($this->b);
    [, $this->publishableB] = issueKey($this->b, type: KeyType::Publishable);
    $this->configA = inAccount($this->a, fn () => AvatarConfig::factory()->create(['name' => 'marker-a-config', 'provider' => 'mock']));
    $this->endpointA = inAccount($this->a, fn () => WebhookEndpoint::factory()->create(['url' => 'https://marker-a.example.com/hook']));
    $this->conversationA = inAccount($this->a, fn () => Conversation::factory()->create(['avatar_config_id' => $this->configA->id, 'status' => ConversationStatus::Live, 'provider_session_ref' => 'marker-a-session', 'external_ref' => 'marker-a-ref']));
    inAccount($this->a, fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'tv-marker-a-key-1234']));
    inAccount($this->a, fn () => ProviderCredential::create(['provider' => 'cartesia', 'api_key' => 'ca-marker-a-key-5678']));
    inAccount($this->a, fn () => LlmCredential::factory()->create(['llm_provider' => 'gemini', 'api_key' => 'gm-marker-a-key-9012']));

    // B's own conversation that calls back, to aim A's session id at.
    $this->callbackToken = bin2hex(random_bytes(32));
    $configB = inAccount($this->b, fn () => AvatarConfig::factory()->create(['provider' => 'tavus', 'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1']]));
    inAccount($this->b, fn () => Conversation::factory()->create(['avatar_config_id' => $configB->id, 'provider' => 'tavus', 'status' => ConversationStatus::Connecting, 'provider_session_ref' => 'b-session', 'callback_token_hash' => hash('sha256', $this->callbackToken)]));
});

/** Everything account A owns, as one comparable string. */
function snapshotOf(Account $account): string
{
    return json_encode(array_map(fn (string $table) => DB::table($table)->where('account_id', $account->id)->orderBy('id')->get()->all(), ISOLATED_TABLES), JSON_THROW_ON_ERROR);
}

/** What would show that A's data reached B: the ids and the marker text A's rows carry. */
function markersOfA(): array
{
    return [
        'marker-a', test()->keyA->id, test()->keyA->key_prefix, test()->configA->id, test()->endpointA->id, test()->conversationA->id,
        test()->endpointA->secret, 'tv-marker', 'ca-marker', 'gm-marker',
    ];
}

/**
 * Each authenticated route and the request that aims account A's resource at account B's key.
 * `kind`: `not_found` (404), `own` (answers B's own view and holds nothing of A's), `closed` (refused for lack of B's own credential).
 *
 * @return array<string, array{kind: string, status: int, request: Closure}>
 */
function isolationCases(): array
{
    $secret = fn (array $extra = []) => ['Authorization' => 'Bearer '.test()->rawB, ...$extra];
    $get = fn (string $uri) => fn () => ['GET', $uri, [], $secret()];
    $send = fn (string $method, string $uri, array $body = []) => fn () => [$method, $uri, $body, $secret()];
    $browser = function (string $method, string $suffix, array $body = []) {
        return function () use ($method, $suffix, $body) {
            $conversation = test()->conversationA;
            // A secret that names A's conversation but is minted for B: the key and the secret agree, the conversation is not B's.
            $token = app(ClientSecret::class)->mint($conversation->id, test()->b->id, now(), 1800)->token;

            return [$method, "/v1/client/conversations/{$conversation->id}/{$suffix}", $body, browserHeaders(test()->publishableB, $token)];
        };
    };
    $a = fn (string $property) => test()->$property;

    return [
        'api-keys.index' => ['kind' => 'own', 'status' => 200, 'request' => $get('/v1/api-keys')],
        'api-keys.store' => ['kind' => 'own', 'status' => 201, 'request' => $send('POST', '/v1/api-keys', ['type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read']])],
        'api-keys.revoke' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['POST', "/v1/api-keys/{$a('keyA')->id}/revoke", [], $secret()]],
        'api-keys.rotate' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['POST', "/v1/api-keys/{$a('keyA')->id}/rotate", [], $secret()]],
        'avatar-configs.index' => ['kind' => 'own', 'status' => 200, 'request' => $get('/v1/avatar-configs')],
        'avatar-configs.store' => ['kind' => 'own', 'status' => 201, 'request' => $send('POST', '/v1/avatar-configs', ['name' => 'B config', 'provider' => 'mock', 'config' => new stdClass])],
        'avatar-configs.show' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['GET', "/v1/avatar-configs/{$a('configA')->id}", [], $secret()]],
        'avatar-configs.update' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['PATCH', "/v1/avatar-configs/{$a('configA')->id}", ['name' => 'taken'], $secret()]],
        'avatar-configs.destroy' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['DELETE', "/v1/avatar-configs/{$a('configA')->id}", [], $secret()]],
        'avatar-configs.sync' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['POST', "/v1/avatar-configs/{$a('configA')->id}/sync", [], $secret()]],
        'catalogue.index' => ['kind' => 'own', 'status' => 200, 'request' => $get('/v1/catalogue')],
        // A holds a Tavus key; B holds none, so B is refused instead of being served with A's.
        'catalogue.show' => ['kind' => 'closed', 'status' => 422, 'request' => $get('/v1/catalogue/tavus/replica')],
        'conversations.store' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['POST', '/v1/conversations', ['avatar_config_id' => $a('configA')->id, 'system_prompt' => 'Hi', 'language' => 'en'], $secret()]],
        'conversations.show' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['GET', "/v1/conversations/{$a('conversationA')->id}", [], $secret()]],
        'conversations.end' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['POST', "/v1/conversations/{$a('conversationA')->id}/end", [], $secret()]],
        'conversations.transcript' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['GET', "/v1/conversations/{$a('conversationA')->id}/transcript", [], $secret()]],
        'client.conversations.connect' => ['kind' => 'not_found', 'status' => 404, 'request' => $browser('POST', 'connect')],
        'client.conversations.events' => ['kind' => 'not_found', 'status' => 404, 'request' => $browser('POST', 'events', ['events' => [['idempotency_key' => 'k1', 'type' => 'lifecycle', 'name' => 'live']]])],
        'client.conversations.end' => ['kind' => 'not_found', 'status' => 404, 'request' => $browser('POST', 'end')],
        'llm-credentials.index' => ['kind' => 'own', 'status' => 200, 'request' => $get('/v1/llm-credentials')],
        'llm-credentials.update' => ['kind' => 'own', 'status' => 200, 'request' => $send('PUT', '/v1/llm-credentials/gemini', ['api_key' => 'gm-b-own-key-3456'])],
        // Deleting what B does not have is a quiet 204 (the call is idempotent); A's credential is still there.
        'llm-credentials.destroy' => ['kind' => 'own', 'status' => 204, 'request' => $send('DELETE', '/v1/llm-credentials/gemini')],
        'llm-models.index' => ['kind' => 'own', 'status' => 200, 'request' => $get('/v1/llm-models')],
        'provider-credentials.index' => ['kind' => 'own', 'status' => 200, 'request' => $get('/v1/provider-credentials')],
        'provider-credentials.update' => ['kind' => 'own', 'status' => 200, 'request' => $send('PUT', '/v1/provider-credentials/tavus', ['api_key' => 'tv-b-own-key-7890'])],
        'provider-credentials.destroy' => ['kind' => 'own', 'status' => 204, 'request' => $send('DELETE', '/v1/provider-credentials/tavus')],
        // A holds a Cartesia key; B holds none, so a preview for B is refused instead of being spoken with A's.
        'voice-preview.store' => ['kind' => 'closed', 'status' => 422, 'request' => $send('POST', '/v1/voice-preview', ['provider' => 'cartesia', 'voice_id' => 'v1', 'tts_engine' => null, 'language' => 'en'])],
        'webhook-endpoints.index' => ['kind' => 'own', 'status' => 200, 'request' => $get('/v1/webhook-endpoints')],
        'webhook-endpoints.store' => ['kind' => 'own', 'status' => 201, 'request' => $send('POST', '/v1/webhook-endpoints', ['url' => 'https://b.example.com/hook'])],
        'webhook-endpoints.show' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['GET', "/v1/webhook-endpoints/{$a('endpointA')->id}", [], $secret()]],
        'webhook-endpoints.update' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['PATCH', "/v1/webhook-endpoints/{$a('endpointA')->id}", ['enabled' => false], $secret()]],
        'webhook-endpoints.destroy' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['DELETE', "/v1/webhook-endpoints/{$a('endpointA')->id}", [], $secret()]],
        'webhook-endpoints.deliveries.index' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['GET', "/v1/webhook-endpoints/{$a('endpointA')->id}/deliveries", [], $secret()]],
        // B's own callback token, aimed at A's provider session id.
        'webhooks.providers.receive' => ['kind' => 'not_found', 'status' => 404, 'request' => fn () => ['POST', '/v1/webhooks/providers/tavus/'.test()->callbackToken, inboundCallback(['conversation_id' => 'marker-a-session']), ['CONTENT_TYPE' => 'application/json']]],
    ];
}

function sendIsolated(array $case): TestResponse
{
    [$method, $uri, $body, $headers] = ($case['request'])();

    if ($method === 'POST' && str_contains($uri, '/webhooks/providers/')) {
        return postInbound('tavus', test()->callbackToken, $body);
    }

    return test()->json($method, $uri, $body, $headers);
}

it('reaches nothing of another account through this route', function (string $route) {
    $case = isolationCases()[$route];
    $before = snapshotOf($this->a);

    $response = sendIsolated($case);

    expect($response->status())->toBe($case['status'], $route.': '.$response->getContent());
    if ($case['kind'] === 'not_found') {
        expect($response->json('code'))->toBe('not_found');
    }
    foreach (markersOfA() as $marker) {
        expect(strtolower($response->getContent()))->not->toContain(strtolower((string) $marker), "$route shows {$marker}");
    }
    expect(snapshotOf($this->a))->toBe($before, "$route changed account A's rows");
})->with(fn () => array_keys(isolationCases()));

it('puts what B writes in B', function (string $route, string $table) {
    $before = DB::table($table)->where('account_id', $this->b->id)->count();

    sendIsolated(isolationCases()[$route]);

    expect(DB::table($table)->where('account_id', $this->b->id)->count())->toBe($before + 1)
        ->and(DB::table($table)->where('account_id', $this->a->id)->count())->toBe(DB::table($table)->where('account_id', $this->a->id)->count());
})->with([
    ['api-keys.store', 'api_keys'],
    ['avatar-configs.store', 'avatar_configs'],
    ['webhook-endpoints.store', 'webhook_endpoints'],
    ['llm-credentials.update', 'llm_credentials'],
    ['provider-credentials.update', 'provider_credentials'],
]);

it('lists only B\'s own rows, and in the same shape A would see', function (string $route, string $uri) {
    $own = test()->getJson($uri, ['Authorization' => 'Bearer '.$this->rawB])->assertOk()->json('data');

    expect(array_column($own, 'id'))->not->toContain($this->configA->id, $this->endpointA->id, $this->keyA->id);
})->with([
    ['avatar-configs.index', '/v1/avatar-configs'],
    ['webhook-endpoints.index', '/v1/webhook-endpoints'],
    ['api-keys.index', '/v1/api-keys'],
]);

it('has every authenticated route in the dataset, and nothing in it that is not a route', function () {
    $authenticated = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => array_intersect(['auth.secret', 'auth.client', 'auth.provider'], $route->gatherMiddleware()) !== [])
        ->map(fn ($route) => $route->getName())
        ->sort()->values()->all();

    $covered = array_keys(isolationCases());
    sort($covered);

    expect($authenticated)->not->toBeEmpty()->and($covered)->toBe($authenticated);
});
