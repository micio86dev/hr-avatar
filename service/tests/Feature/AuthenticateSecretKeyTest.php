<?php

use App\Accounts\CurrentAccount;
use App\Keys\KeyMode;
use App\Keys\KeyType;
use App\Models\Account;
use App\Models\ApiKey;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->account = Account::factory()->create();

    Route::middleware('auth.secret')->get('/v1/_probe', fn () => response()->json([
        'account' => app(CurrentAccount::class)->id(),
        'key_id' => request()->attributes->get('api_key_id'),
        'key_mode' => request()->attributes->get('key_mode'),
        'key_scopes' => request()->attributes->get('key_scopes'),
    ]));
});

function bearer(string $raw): array
{
    return ['Authorization' => "Bearer {$raw}"];
}

it('rejects a request with missing or malformed credentials', function (array $headers) {
    $response = $this->getJson('/v1/_probe', $headers);

    $response->assertStatus(401);
    expect($response->json('code'))->toBe('unauthenticated');
})->with([
    'no header' => [[]],
    'empty bearer' => [['Authorization' => 'Bearer ']],
    'other scheme' => [['Authorization' => 'Basic dXNlcjpwYXNz']],
    'not a key' => [['Authorization' => 'Bearer not-a-key']],
]);

it('authenticates a live key without an Origin header and binds the account', function () {
    [$key, $raw] = issueKey($this->account, ['scopes' => ['conversations:read']]);

    $this->getJson('/v1/_probe', bearer($raw))
        ->assertOk()
        ->assertExactJson([
            'account' => $this->account->id,
            'key_id' => $key->id,
            'key_mode' => 'live',
            'key_scopes' => ['conversations:read'],
        ]);
});

it('forgets the account once the request is over', function () {
    [, $raw] = issueKey($this->account);

    $this->getJson('/v1/_probe', bearer($raw))->assertOk();

    expect(app(CurrentAccount::class)->id())->toBeNull();
    $this->getJson('/v1/_probe')->assertStatus(401);
});

it('refuses a live secret key presented with an Origin header, whatever the origin', function (string $origin) {
    [, $raw] = issueKey($this->account);

    $response = $this->getJson('/v1/_probe', [...bearer($raw), 'Origin' => $origin]);

    $response->assertStatus(403);
    expect($response->json('code'))->toBe('secret_key_origin_forbidden');
})->with(['https://app.example.com', 'null', 'http://localhost:3000']);

it('accepts a test secret key with an Origin header', function () {
    [, $raw] = issueKey($this->account, mode: KeyMode::Test);

    $this->getJson('/v1/_probe', [...bearer($raw), 'Origin' => 'https://app.example.com'])->assertOk();
});

it('refuses a publishable key presented as a bearer', function () {
    [, $raw] = issueKey($this->account, type: KeyType::Publishable);

    $response = $this->getJson('/v1/_probe', bearer($raw));

    $response->assertStatus(401);
    expect($response->json('code'))->toBe('unauthenticated');
});

it('distinguishes an expired and a revoked key only after the hash matched', function (array $state, string $code) {
    [, $raw] = issueKey($this->account, $state);

    $response = $this->getJson('/v1/_probe', bearer($raw));

    $response->assertStatus(401);
    expect($response->json('code'))->toBe($code);
})->with([
    'expired' => [['expires_at' => now()->subSecond()], 'key_expired'],
    'revoked' => [['revoked_at' => now()->subMinute()], 'key_revoked'],
]);

it('answers an unknown well-formed key as unauthenticated', function () {
    $response = $this->getJson('/v1/_probe', bearer('hra_sk_live_'.str_repeat('0', 96)));

    expect($response->json('code'))->toBe('unauthenticated');
});

it('limits failed authentication to 30 per minute per IP', function () {
    foreach (range(1, 30) as $attempt) {
        $this->getJson('/v1/_probe', bearer('hra_sk_live_'.str_repeat('0', 96)))->assertStatus(401);
    }

    $response = $this->getJson('/v1/_probe', bearer('hra_sk_live_'.str_repeat('0', 96)));

    $response->assertStatus(429);
    expect($response->json('code'))->toBe('rate_limited')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('applies the failed-auth limit to the address, not to the key', function () {
    [, $raw] = issueKey($this->account);

    foreach (range(1, 30) as $attempt) {
        $this->getJson('/v1/_probe')->assertStatus(401);
    }

    $this->getJson('/v1/_probe', bearer($raw))->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/v1/_probe', bearer($raw))->assertOk();
});

it('does not count an Origin refusal as a failed authentication', function () {
    [, $raw] = issueKey($this->account);

    foreach (range(1, 31) as $attempt) {
        $this->getJson('/v1/_probe', [...bearer($raw), 'Origin' => 'https://app.example.com'])->assertStatus(403);
    }

    $this->getJson('/v1/_probe', bearer($raw))->assertOk();
});

it('resolves implicit route bindings inside the authenticated account only', function () {
    // Real routes live in the `api` group, which is where Laravel puts SubstituteBindings.
    Route::middleware(['api', 'auth.secret'])->get('/v1/_keys/{apiKey}', fn (ApiKey $apiKey) => ['id' => $apiKey->id]);
    [$own, $raw] = issueKey($this->account);
    [$foreign] = issueKey(Account::factory()->create());

    $this->getJson("/v1/_keys/{$own->id}", bearer($raw))->assertOk()->assertExactJson(['id' => $own->id]);

    $response = $this->getJson("/v1/_keys/{$foreign->id}", bearer($raw));
    $response->assertStatus(404);
    expect($response->json('code'))->toBe('not_found');
});
