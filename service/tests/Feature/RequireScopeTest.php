<?php

use App\Keys\Scope;
use App\Models\Account;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->account = Account::factory()->create();
});

function callWithScopes(array $scopes, string $required): TestResponse
{
    Route::middleware(['auth.secret', "scope:{$required}"])->get("/v1/_probe/{$required}", fn () => ['ok' => true]);
    [, $raw] = issueKey(test()->account, ['scopes' => $scopes]);

    return test()->getJson("/v1/_probe/{$required}", ['Authorization' => "Bearer {$raw}"]);
}

it('refuses a key that lacks the scope', function () {
    $response = callWithScopes(['conversations:read'], 'conversations:write');

    $response->assertStatus(403);
    expect($response->json('code'))->toBe('insufficient_scope');
});

it('lets a key that holds the scope through', function () {
    callWithScopes(['conversations:read', 'conversations:write'], 'conversations:write')->assertOk();
});

it('lets the wildcard through every scope', function (Scope $scope) {
    callWithScopes(['*'], $scope->value)->assertOk();
})->with(array_filter(Scope::cases(), fn (Scope $scope) => $scope !== Scope::Wildcard));

it('refuses a key with no scopes at all', function () {
    callWithScopes([], 'conversations:read')->assertStatus(403);
});

it('is reached only after authentication', function () {
    Route::middleware(['auth.secret', 'scope:conversations:read'])->get('/v1/_probe', fn () => ['ok' => true]);

    $response = $this->getJson('/v1/_probe');

    $response->assertStatus(401);
    expect($response->json('code'))->toBe('unauthenticated');
});

it('denies a route that names a scope which does not exist, even to the wildcard, rather than failing with a 500', function (array $scopes) {
    // A typo in a route definition must be a deliberate refusal that is logged, not an unhandled ValueError.
    $logs = captureLogs();

    $response = callWithScopes($scopes, 'conversation:write');

    $response->assertStatus(403);
    expect($response->json('code'))->toBe('insufficient_scope')
        ->and(implode("\n", $logs->getArrayCopy()))->toContain('Route requires an unknown scope')->toContain('conversation:write');
})->with(['wildcard' => [['*']], 'a key that holds the real scope' => [['conversations:write']]]);
