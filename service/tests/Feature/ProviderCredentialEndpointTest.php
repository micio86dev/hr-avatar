<?php

use App\Models\Account;
use App\Models\ProviderCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['credentials:manage']]);
    config()->set('hr-avatar.platform_keys', ['tavus' => null, 'heygen' => null, 'cartesia' => null, 'elevenlabs' => null]);
});

function providerCall(string $method, string $path = '', array $body = [], ?string $raw = null): TestResponse
{
    $headers = ['Authorization' => 'Bearer '.($raw ?? test()->raw)];
    $uri = '/v1/provider-credentials'.$path;

    return $method === 'get' ? test()->getJson($uri, $headers) : test()->{$method.'Json'}($uri, $body, $headers);
}

function storedKey(Account $account, string $provider): ?string
{
    return inAccount($account, fn () => ProviderCredential::where('provider', $provider)->first()?->api_key);
}

it('stores a key and answers with the provider, the source, the last four and when, never the key', function () {
    $response = providerCall('put', '/tavus', ['api_key' => 'tv_abcdefgh1234']);

    $response->assertOk();
    expect(array_keys($response->json()))->toBe(['provider', 'source', 'last_four', 'updated_at'])
        ->and($response->json('provider'))->toBe('tavus')
        ->and($response->json('source'))->toBe('account')
        ->and($response->json('last_four'))->toBe('1234')
        ->and($response->json('updated_at'))->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/')
        ->and($response->getContent())->not->toContain('tv_abcdefgh1234')
        ->and(storedKey($this->account, 'tavus'))->toBe('tv_abcdefgh1234');
});

it('replaces the key and keeps a single row', function () {
    providerCall('put', '/tavus', ['api_key' => 'tv_first_key_0001'])->assertOk();
    providerCall('put', '/tavus', ['api_key' => 'tv_second_key_0002'])->assertOk()->assertJsonPath('last_four', '0002');

    expect(storedKey($this->account, 'tavus'))->toBe('tv_second_key_0002')
        ->and(DB::table('provider_credentials')->count())->toBe(1);
});

it('computes the last four itself, whatever the body claims', function () {
    $response = providerCall('put', '/tavus', ['api_key' => 'tv_abcdefgh1234', 'last_four' => 'zzzz', 'account_id' => 'someone-else']);

    $response->assertOk();
    expect($response->json('last_four'))->toBe('1234')
        ->and(DB::table('provider_credentials')->value('account_id'))->toBe($this->account->id);
});

it('answers 404 for a provider that takes no credential', function (string $provider) {
    $response = providerCall('put', "/{$provider}", ['api_key' => 'tv_abcdefgh1234']);

    $response->assertStatus(404);
    expect($response->json('code'))->toBe('not_found');
    providerCall('delete', "/{$provider}")->assertStatus(404);
})->with(['acme', 'mock', 'liveavatar']);

it('refuses a missing, empty, oversize or non-string key', function (mixed $key) {
    $response = providerCall('put', '/tavus', ['api_key' => $key]);

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('validation_failed')
        ->and($response->json('errors'))->toHaveKey('api_key')
        ->and(DB::table('provider_credentials')->count())->toBe(0);
})->with([
    'null' => [null],
    'empty' => [''],
    'blank' => ['   '],
    'too long' => [str_repeat('k', 513)],
    'a number' => [12345],
    'a list' => [['x']],
]);

it('accepts a key of 1 and of 512 characters', function (int $length) {
    providerCall('put', '/tavus', ['api_key' => str_repeat('k', $length)])->assertOk();
})->with([1, 512]);

it('does not echo an oversize key in the validation error', function () {
    $key = str_repeat('Z', 600);

    expect(providerCall('put', '/tavus', ['api_key' => $key])->getContent())->not->toContain($key);
});

it('requires the credentials:manage scope', function (string $method, string $path) {
    [, $narrow] = issueKey($this->account, ['scopes' => ['avatar_configs:read']]);

    $response = providerCall($method, $path, ['api_key' => 'tv_abcdefgh1234'], $narrow);

    $response->assertStatus(403);
    expect($response->json('code'))->toBe('insufficient_scope');
})->with([['get', ''], ['put', '/tavus'], ['delete', '/tavus']]);

it('requires authentication', function (string $method, string $path) {
    ($method === 'get' ? $this->getJson('/v1/provider-credentials'.$path) : $this->{$method.'Json'}('/v1/provider-credentials'.$path, []))->assertStatus(401);
})->with([['get', ''], ['put', '/tavus'], ['delete', '/tavus']]);

it('lists one entry per credential provider with its source', function () {
    providerCall('put', '/tavus', ['api_key' => 'tv_abcdefgh1234'])->assertOk();
    config()->set('hr-avatar.platform_keys.heygen', 'platform-heygen-key-9999');

    $response = providerCall('get')->assertOk();

    expect(array_keys($response->json()))->toBe(['data'])
        ->and($response->json('data'))->toBe([
            ['provider' => 'tavus', 'source' => 'account', 'last_four' => '1234'],
            ['provider' => 'heygen', 'source' => 'platform', 'last_four' => null],
            ['provider' => 'cartesia', 'source' => 'none', 'last_four' => null],
            ['provider' => 'elevenlabs', 'source' => 'none', 'last_four' => null],
        ])
        ->and($response->getContent())->not->toContain('platform-heygen-key-9999')->not->toContain('next_cursor');
});

it('is not paginated: a limit or cursor changes nothing', function () {
    expect(providerCall('get', '?limit=1')->json('data'))->toHaveCount(4);
});

it('shows no last four for a short key', function (string $key, ?string $lastFour) {
    providerCall('put', '/tavus', ['api_key' => $key])->assertOk()->assertJsonPath('last_four', $lastFour);

    expect(collect(providerCall('get')->json('data'))->firstWhere('provider', 'tavus')['last_four'])->toBe($lastFour);
})->with([
    'eight characters' => ['abcd1234', null],
    'eleven characters' => ['abcdefg1234', null],
    'twelve characters' => ['abcdefgh1234', '1234'],
]);

it('falls back to the platform key when the account credential is deleted', function () {
    config()->set('hr-avatar.platform_keys.tavus', 'platform-tavus-key-9999');
    providerCall('put', '/tavus', ['api_key' => 'tv_abcdefgh1234'])->assertOk();

    providerCall('delete', '/tavus')->assertNoContent();

    $entry = collect(providerCall('get')->json('data'))->firstWhere('provider', 'tavus');
    expect($entry)->toBe(['provider' => 'tavus', 'source' => 'platform', 'last_four' => null])
        ->and(DB::table('provider_credentials')->count())->toBe(0);
});

it('deletes idempotently', function () {
    providerCall('delete', '/tavus')->assertNoContent();
    providerCall('put', '/tavus', ['api_key' => 'tv_abcdefgh1234'])->assertOk();
    providerCall('delete', '/tavus')->assertNoContent();
    providerCall('delete', '/tavus')->assertNoContent();
});

it('shows and changes only the calling account\'s credentials', function () {
    $other = Account::factory()->create();
    [, $otherRaw] = issueKey($other, ['scopes' => ['credentials:manage']]);
    providerCall('put', '/tavus', ['api_key' => 'tv_account_a_key_1111'])->assertOk();

    $theirs = providerCall('get', '', [], $otherRaw)->json('data');
    expect(collect($theirs)->firstWhere('provider', 'tavus')['source'])->toBe('none');

    providerCall('put', '/tavus', ['api_key' => 'tv_account_b_key_2222'], $otherRaw)->assertOk();
    providerCall('delete', '/heygen', [], $otherRaw)->assertNoContent();
    providerCall('delete', '/tavus', [], $otherRaw)->assertNoContent();

    expect(storedKey($this->account, 'tavus'))->toBe('tv_account_a_key_1111')
        ->and(storedKey($other, 'tavus'))->toBeNull();
});
