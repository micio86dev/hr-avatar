<?php

use App\Accounts\CurrentAccount;
use App\Http\Middleware\RejectKeyInQuery;
use App\Keys\ClientSecret;
use App\Keys\KeyType;
use App\Models\Account;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

const CLIENT_ORIGIN = 'https://app.example.com';

beforeEach(function () {
    $this->account = Account::factory()->create();
    [$this->publishable, $this->pk] = issueKey($this->account, type: KeyType::Publishable);
    $this->token = (new ClientSecret)->mint('conv_one', $this->account->id, now(), 1800)->token;

    Route::middleware(['api', RejectKeyInQuery::class, 'auth.client'])
        ->post('/v1/client/conversations/{conversation}/probe', fn (string $conversation) => response()->json([
            'account' => app(CurrentAccount::class)->id(),
            'key_id' => request()->attributes->get('api_key_id'),
            'conversation' => $conversation,
        ]));
});

/**
 * @param  array<string, string|null>  $overrides  header => value; null drops the header
 */
function clientCall(array $overrides = [], string $conversation = 'conv_one'): TestResponse
{
    $headers = array_filter([
        'X-HRA-Publishable-Key' => test()->pk,
        'Authorization' => 'Bearer '.test()->token,
        'Origin' => CLIENT_ORIGIN,
        ...$overrides,
    ], fn ($value) => $value !== null);

    return test()->postJson("/v1/client/conversations/{$conversation}/probe", [], $headers);
}

function codeOf(TestResponse $response): ?string
{
    return $response->json('code');
}

it('authorizes a publishable key, an allowed origin and a client secret for that conversation', function () {
    $response = clientCall()->assertOk();

    expect($response->json())->toBe([
        'account' => $this->account->id,
        'key_id' => $this->publishable->id,
        'conversation' => 'conv_one',
    ]);
});

it('forgets the account once the request is over', function () {
    clientCall()->assertOk();

    expect(app(CurrentAccount::class)->id())->toBeNull();
});

it('grants nothing to a publishable key on its own', function () {
    $response = clientCall(['Authorization' => null]);

    $response->assertStatus(401);
    expect(codeOf($response))->toBe('unauthenticated');
});

it('refuses a missing or unusable publishable key with unauthenticated', function (?string $header) {
    $response = clientCall(['X-HRA-Publishable-Key' => $header]);

    $response->assertStatus(401);
    expect(codeOf($response))->toBe('unauthenticated');
})->with([
    'missing' => [null],
    'garbage' => ['not-a-key'],
    'a well-formed key that does not exist' => ['hra_pk_live_'.str_repeat('0', 48)],
    'a secret key in the publishable header' => fn () => issueKey(Account::factory()->create())[1],
]);

it('refuses an expired or revoked publishable key with its own code, once the key matched', function (array $state, string $code) {
    [, $raw] = issueKey($this->account, $state, KeyType::Publishable);

    $response = clientCall(['X-HRA-Publishable-Key' => $raw]);

    $response->assertStatus(401);
    expect(codeOf($response))->toBe($code);
})->with([
    'expired' => [['expires_at' => now()->subSecond()], 'key_expired'],
    'revoked' => [['revoked_at' => now()->subMinute()], 'key_revoked'],
]);

it('requires the Origin header to be exactly an allowed origin', function (?string $origin) {
    $response = clientCall(['Origin' => $origin]);

    $response->assertStatus(403);
    expect(codeOf($response))->toBe('origin_not_allowed')
        ->and($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
})->with([
    'missing' => [null],
    'another site' => ['https://evil.example.com'],
    'a subdomain' => ['https://sub.app.example.com'],
    'another scheme' => ['http://app.example.com'],
    'another port' => ['https://app.example.com:8443'],
    'a trailing slash' => ['https://app.example.com/'],
    'upper case' => ['HTTPS://APP.EXAMPLE.COM'],
    'the opaque origin' => ['null'],
]);

it('judges the origin before the bearer token', function () {
    $response = clientCall(['Origin' => 'https://evil.example.com', 'Authorization' => null]);

    expect(codeOf($response))->toBe('origin_not_allowed');
});

it('refuses a missing bearer or a bearer that is not a client secret', function (?string $authorization, string $code) {
    $response = clientCall(['Authorization' => $authorization]);

    $response->assertStatus(401);
    expect(codeOf($response))->toBe($code);
})->with([
    'missing' => [null, 'unauthenticated'],
    'other scheme' => ['Basic dXNlcjpwYXNz', 'unauthenticated'],
    'empty bearer' => ['Bearer ', 'unauthenticated'],
    'garbage' => ['Bearer not-a-jwt', 'client_secret_invalid'],
]);

it('refuses a secret key presented as the client secret', function () {
    [, $secret] = issueKey($this->account);

    $response = clientCall(['Authorization' => "Bearer {$secret}"]);

    $response->assertStatus(401);
    expect(codeOf($response))->toBe('client_secret_invalid');
});

it('tells an expired client secret from a forged one', function () {
    $expired = (new ClientSecret)->mint('conv_one', $this->account->id, now()->subHours(3), 600)->token;

    $response = clientCall(['Authorization' => "Bearer {$expired}"]);

    $response->assertStatus(401);
    expect(codeOf($response))->toBe('client_secret_expired');
});

it('refuses a tampered client secret and one signed with alg none', function (string $kind) {
    [$header, $payload, $signature] = explode('.', $this->token);
    $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    $claims['sub'] = 'conv_two';
    $b64 = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

    $forged = match ($kind) {
        'tampered' => "{$header}.".$b64($claims).".{$signature}",
        'none' => $b64(['alg' => 'none', 'typ' => 'JWT']).'.'.$b64($claims).'.',
    };

    $response = clientCall(['Authorization' => "Bearer {$forged}"], 'conv_two');

    $response->assertStatus(401);
    expect(codeOf($response))->toBe('client_secret_invalid');
})->with(['tampered', 'none']);

it('counts every 401 on the failed-authentication budget of the address, and nothing else', function () {
    foreach (range(1, 30) as $attempt) {
        clientCall(['Authorization' => null])->assertStatus(401);
    }

    $response = clientCall();
    $response->assertStatus(429);
    expect(codeOf($response))->toBe('rate_limited')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('does not count a 403 as a failed authentication', function () {
    foreach (range(1, 31) as $attempt) {
        clientCall(['Origin' => 'https://evil.example.com'])->assertStatus(403);
    }

    clientCall()->assertOk();
});

it('is reached after the key-in-query check', function () {
    $response = $this->postJson('/v1/client/conversations/conv_one/probe?api_key='.$this->pk, [], ['X-HRA-Publishable-Key' => $this->pk]);

    $response->assertStatus(400);
    expect(codeOf($response))->toBe('api_key_in_query');
});

it('authenticates before a route binding is resolved, so a stranger cannot probe for conversations', function () {
    Route::bind('boundConversation', fn () => throw new ModelNotFoundException);
    Route::middleware(['api', 'auth.client'])->post('/v1/client/bound/{boundConversation}', fn () => ['ok' => true]);

    $anonymous = $this->postJson('/v1/client/bound/conv_unknown', [], ['Origin' => CLIENT_ORIGIN]);
    expect($anonymous->status())->toBe(401);
});
