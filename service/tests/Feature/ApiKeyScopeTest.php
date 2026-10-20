<?php

use App\Keys\KeyMode;
use App\Models\Account;
use App\Models\ApiKey;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => $this->account = Account::factory()->create());

function createAs(string $raw, array $body): TestResponse
{
    return test()->postJson('/v1/api-keys', $body, ['Authorization' => "Bearer {$raw}"]);
}

function keyCount(): int
{
    return ApiKey::withoutGlobalScopes()->count();
}

it('refuses to issue a key with a scope the caller lacks, and creates nothing', function (array $callerScopes, array $requested) {
    [, $raw] = issueKey($this->account, ['scopes' => $callerScopes]);
    $before = keyCount();

    $response = createAs($raw, ['type' => 'secret', 'mode' => 'live', 'scopes' => $requested]);

    $response->assertStatus(403);
    expect($response->json('code'))->toBe('insufficient_scope')
        ->and(keyCount())->toBe($before);
})->with([
    'a different scope' => [['api_keys:manage', 'conversations:read'], ['conversations:write']],
    'one of several beyond the caller' => [['api_keys:manage', 'conversations:read'], ['conversations:read', 'conversations:write']],
    'the wildcard' => [['api_keys:manage', 'conversations:read'], ['*']],
]);

it('lets a caller issue a key within its own scopes', function () {
    [, $raw] = issueKey($this->account, ['scopes' => ['api_keys:manage', 'conversations:read']]);

    createAs($raw, ['type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read']])->assertCreated();
});

it('lets the wildcard caller issue any scope, the wildcard included', function (string $scope) {
    [, $raw] = issueKey($this->account, ['scopes' => ['*']]);

    createAs($raw, ['type' => 'secret', 'mode' => 'live', 'scopes' => [$scope]])->assertCreated();
})->with(['*', 'webhook_endpoints:manage', 'credentials:manage']);

it('lets a caller with only api_keys:manage issue a publishable key, which carries no scopes', function () {
    [, $raw] = issueKey($this->account, ['scopes' => ['api_keys:manage']]);

    createAs($raw, ['type' => 'publishable', 'mode' => 'live', 'allowed_origins' => ['https://app.example.com']])->assertCreated();
});

it('refuses to issue a key of the other mode, whatever the caller\'s scopes', function (KeyMode $callerMode, string $requested) {
    [, $raw] = issueKey($this->account, ['scopes' => ['*']], mode: $callerMode);
    $before = keyCount();

    $response = createAs($raw, ['type' => 'secret', 'mode' => $requested, 'scopes' => ['conversations:read']]);

    $response->assertStatus(403);
    expect($response->json('code'))->toBe('forbidden')
        ->and(keyCount())->toBe($before);
})->with([
    'test caller, live key' => [KeyMode::Test, 'live'],
    'live caller, test key' => [KeyMode::Live, 'test'],
]);

it('lets a test caller issue test keys', function () {
    [, $raw] = issueKey($this->account, ['scopes' => ['api_keys:manage', 'conversations:read']], mode: KeyMode::Test);

    $response = createAs($raw, ['type' => 'secret', 'mode' => 'test', 'scopes' => ['conversations:read']]);

    $response->assertCreated();
    expect($response->json('key'))->toStartWith('hra_sk_test_');
});

it('reports a malformed request before any permission', function () {
    [, $raw] = issueKey($this->account, ['scopes' => ['api_keys:manage']]);

    createAs($raw, ['type' => 'secret', 'mode' => 'live', 'scopes' => ['bogus:scope']])->assertStatus(422);
});

it('stores a repeated scope once', function () {
    [, $raw] = issueKey($this->account, ['scopes' => ['*']]);

    $response = createAs($raw, ['type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read', 'conversations:read', 'conversations:write']]);

    expect($response->json('scopes'))->toBe(['conversations:read', 'conversations:write']);
});
