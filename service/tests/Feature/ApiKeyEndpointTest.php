<?php

use App\Keys\KeyGenerator;
use App\Keys\KeyMode;
use App\Keys\KeyType;
use App\Models\Account;
use App\Models\ApiKey;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->account = Account::factory()->create();
    [$this->caller, $this->raw] = issueKey($this->account, ['scopes' => ['*']]);
});

function createKey(array $body, ?string $raw = null): TestResponse
{
    return test()->postJson('/v1/api-keys', $body, ['Authorization' => 'Bearer '.($raw ?? test()->raw)]);
}

function listKeys(string $query = '', ?string $raw = null): TestResponse
{
    return test()->getJson('/v1/api-keys'.$query, ['Authorization' => 'Bearer '.($raw ?? test()->raw)]);
}

const RESOURCE_MEMBERS = ['id', 'type', 'mode', 'key_prefix', 'scopes', 'allowed_origins', 'expires_at', 'revoked_at', 'last_used_at', 'rotated_from_id', 'created_at'];

it('creates a secret key and returns it exactly once', function () {
    $response = createKey(['type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read']]);

    $response->assertCreated();
    $body = $response->json();

    expect($body['key'])->toMatch('/^hra_sk_live_[0-9a-f]{96}$/')
        ->and(array_keys($body))->toEqualCanonicalizing([...RESOURCE_MEMBERS, 'key'])
        ->and($body['type'])->toBe('secret')
        ->and($body['mode'])->toBe('live')
        ->and($body['scopes'])->toBe(['conversations:read'])
        ->and($body['allowed_origins'])->toBeNull()
        ->and($body['key_prefix'])->toBe(KeyGenerator::prefixOf($body['key']))
        ->and($body['rotated_from_id'])->toBeNull();

    $stored = inAccount($this->account, fn () => ApiKey::findOrFail($body['id']));
    expect($stored->key_hash)->toBe(KeyGenerator::hash($body['key']));
});

it('creates a publishable key with normalized origins and no scopes', function () {
    $response = createKey(['type' => 'publishable', 'mode' => 'live', 'allowed_origins' => ['HTTPS://App.Example.com/', 'http://localhost:3000', 'https://app.example.com']]);

    $response->assertCreated();
    expect($response->json('key'))->toMatch('/^hra_pk_live_[0-9a-f]{48}$/')
        ->and($response->json('allowed_origins'))->toBe(['https://app.example.com', 'http://localhost:3000'])
        ->and($response->json('scopes'))->toBe([]);
});

it('accepts a future expiry and refuses a past one', function () {
    $future = now()->addDay()->startOfSecond();

    $created = createKey(['type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read'], 'expires_at' => $future->toIso8601String()]);
    $created->assertCreated();
    expect($created->json('expires_at'))->toBe($future->toIso8601ZuluString());

    $refused = createKey(['type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read'], 'expires_at' => now()->subMinute()->toIso8601String()]);
    $refused->assertStatus(422);
    expect($refused->json('errors'))->toHaveKey('expires_at');
});

it('refuses a publishable key without usable origins', function (array $extra, string $field) {
    $response = createKey(['type' => 'publishable', 'mode' => 'live', ...$extra]);

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('validation_failed')
        ->and($response->json('errors'))->toHaveKey($field);
})->with([
    'none' => [[], 'allowed_origins'],
    'empty list' => [['allowed_origins' => []], 'allowed_origins'],
    'a wildcard' => [['allowed_origins' => ['*']], 'allowed_origins'],
    'a wildcard subdomain' => [['allowed_origins' => ['https://*.example.com']], 'allowed_origins'],
    'plain http' => [['allowed_origins' => ['http://app.example.com']], 'allowed_origins'],
    'a path' => [['allowed_origins' => ['https://app.example.com/x']], 'allowed_origins'],
    'twenty one' => [['allowed_origins' => array_map(fn ($i) => "https://app{$i}.example.com", range(1, 21))], 'allowed_origins'],
    'not strings' => [['allowed_origins' => [123]], 'allowed_origins'],
    'scopes on a publishable key' => [['allowed_origins' => ['https://app.example.com'], 'scopes' => ['conversations:read']], 'scopes'],
]);

it('accepts loopback over plain http for a publishable key', function () {
    createKey(['type' => 'publishable', 'mode' => 'live', 'allowed_origins' => ['http://localhost:3000', 'http://127.0.0.1:5173']])->assertCreated();
});

it('refuses a secret key that carries origins', function () {
    $response = createKey(['type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read'], 'allowed_origins' => ['https://app.example.com']]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('allowed_origins');
});

it('requires an explicit non-empty scope list for a secret key', function (array $extra) {
    $response = createKey(['type' => 'secret', 'mode' => 'live', ...$extra]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('scopes');
})->with([
    'missing' => [[]],
    'empty' => [['scopes' => []]],
]);

it('refuses an unknown scope', function () {
    $response = createKey(['type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read', 'bogus:scope']]);

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('validation_failed')
        ->and($response->json('errors'))->toHaveKey('scopes.1');
});

it('refuses an unknown type or mode', function (array $body, string $field) {
    $response = createKey($body);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey($field);
})->with([
    'type' => [['type' => 'master', 'mode' => 'live', 'scopes' => ['*']], 'type'],
    'no type' => [['mode' => 'live', 'scopes' => ['*']], 'type'],
    'mode' => [['type' => 'secret', 'mode' => 'prod', 'scopes' => ['*']], 'mode'],
    'no mode' => [['type' => 'secret', 'scopes' => ['*']], 'mode'],
]);

it('stamps the caller\'s account and ignores fields a caller must not choose', function () {
    $other = Account::factory()->create();

    $response = createKey([
        'type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read'],
        'account_id' => $other->id, 'key_hash' => str_repeat('a', 64), 'revoked_at' => now()->toIso8601String(), 'rotated_from_id' => $this->caller->id,
    ]);

    $response->assertCreated();
    $stored = ApiKey::withoutGlobalScopes()->findOrFail($response->json('id'));
    expect($stored->account_id)->toBe($this->account->id)
        ->and($stored->revoked_at)->toBeNull()
        ->and($stored->rotated_from_id)->toBeNull()
        ->and($stored->key_hash)->not->toBe(str_repeat('a', 64));
});

it('lists the account\'s keys without ever returning a key or a hash', function () {
    $created = createKey(['type' => 'publishable', 'mode' => 'live', 'allowed_origins' => ['https://app.example.com']])->json();
    $foreign = issueKey(Account::factory()->create())[0];

    $response = listKeys()->assertOk();
    $content = $response->getContent();
    $listed = collect($response->json('data'));

    expect(array_keys($response->json()))->toBe(['data', 'next_cursor'])
        ->and($listed->pluck('id')->all())->toContain($created['id'], $this->caller->id)->not->toContain($foreign->id)
        ->and($listed->every(fn (array $row) => array_keys($row) === RESOURCE_MEMBERS))->toBeTrue()
        ->and($content)->not->toContain($created['key'])->not->toContain('key_hash')->not->toContain($this->raw);
});

it('paginates the listing', function () {
    foreach (range(1, 4) as $i) {
        createKey(['type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read']])->assertCreated();
    }

    $first = listKeys('?limit=3')->assertOk()->json();
    $second = listKeys('?limit=3&cursor='.urlencode($first['next_cursor']))->assertOk()->json();

    expect($first['data'])->toHaveCount(3)
        ->and($second['data'])->toHaveCount(2)
        ->and($second['next_cursor'])->toBeNull();
    listKeys('?limit=0')->assertStatus(422);
});

it('requires the api_keys:manage scope', function () {
    [, $narrow] = issueKey($this->account, ['scopes' => ['conversations:read']]);
    $headers = ['Authorization' => "Bearer {$narrow}"];

    foreach ([$this->getJson('/v1/api-keys', $headers), $this->postJson('/v1/api-keys', [], $headers)] as $denied) {
        $denied->assertStatus(403);
        expect($denied->json('code'))->toBe('insufficient_scope');
    }
});

it('requires authentication', function () {
    foreach ([$this->getJson('/v1/api-keys'), $this->postJson('/v1/api-keys', [])] as $anonymous) {
        $anonymous->assertStatus(401);
        expect($anonymous->json('code'))->toBe('unauthenticated');
    }
});

it('refuses a publishable key presented as the bearer', function () {
    [, $publishable] = issueKey($this->account, type: KeyType::Publishable);

    listKeys('', $publishable)->assertStatus(401);
});

it('lets a test-mode caller see keys of both modes, because mode labels the key space only', function () {
    [, $testRaw] = issueKey($this->account, ['scopes' => ['api_keys:manage']], mode: KeyMode::Test);
    // Listing is not mode-bound, and a caller holding nothing but api_keys:manage may list.

    $modes = collect(listKeys('', $testRaw)->assertOk()->json('data'))->pluck('mode')->unique()->sort()->values()->all();

    expect($modes)->toBe(['live', 'test']);
});
