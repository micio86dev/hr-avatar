<?php

use App\Models\Account;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function keyRow(array $overrides = []): array
{
    return [
        'id' => (string) Str::ulid(),
        'account_id' => test()->account->id,
        'type' => 'secret',
        'mode' => 'live',
        'key_prefix' => 'hra_sk_live_'.bin2hex(random_bytes(4)),
        'key_hash' => hash('sha256', random_bytes(16)),
        'scopes' => json_encode(['*']),
        'allowed_origins' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

beforeEach(fn () => $this->account = Account::factory()->create());

it('accepts a valid secret key and a valid publishable key', function () {
    DB::table('api_keys')->insert(keyRow());
    DB::table('api_keys')->insert(keyRow([
        'type' => 'publishable',
        'key_prefix' => 'hra_pk_live_'.bin2hex(random_bytes(4)),
        'scopes' => '[]',
        'allowed_origins' => json_encode(['https://app.example.com']),
    ]));

    expect(DB::table('api_keys')->count())->toBe(2);
});

it('rejects a row that breaks a constraint', function (string $case, array $overrides) {
    expect(fn () => DB::table('api_keys')->insert(keyRow($overrides)))->toThrow(QueryException::class);
})->with([
    'unknown type' => ['type', ['type' => 'bogus']],
    'unknown mode' => ['mode', ['mode' => 'prod']],
    'secret key with origins' => ['origins', ['allowed_origins' => json_encode(['https://a.example.com'])]],
    'publishable key without origins' => ['no origins', ['type' => 'publishable', 'scopes' => '[]']],
    'publishable key with scopes' => ['scopes', ['type' => 'publishable', 'allowed_origins' => json_encode(['https://a.example.com'])]],
    'publishable key with 21 origins' => ['too many', ['type' => 'publishable', 'scopes' => '[]', 'allowed_origins' => json_encode(array_map(fn ($i) => "https://a{$i}.example.com", range(1, 21)))]],
    'publishable key with an empty origin list' => ['empty', ['type' => 'publishable', 'scopes' => '[]', 'allowed_origins' => '[]']],
]);

it('keeps the hash unique', function () {
    $hash = hash('sha256', 'same');
    DB::table('api_keys')->insert(keyRow(['key_hash' => $hash]));

    expect(fn () => DB::table('api_keys')->insert(keyRow(['key_hash' => $hash])))->toThrow(QueryException::class);
});

it('refuses a key for an account that does not exist', function () {
    expect(fn () => DB::table('api_keys')->insert(keyRow(['account_id' => (string) Str::ulid()])))->toThrow(QueryException::class);
});
