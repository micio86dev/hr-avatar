<?php

use App\AvatarProviders\ResourceKind;
use App\Models\Account;
use App\Models\ProviderResource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(fn () => $this->account = Account::factory()->create());

function ledgerEntry(array $attributes = [], ?Account $account = null): ProviderResource
{
    return inAccount($account ?? test()->account, fn () => ProviderResource::create([
        'provider' => 'heygen',
        'key_fingerprint' => str_repeat('a', 16),
        'kind' => ResourceKind::Voice,
        'natural_key' => 'cartesia:voice-1:sonic-2',
        'external_id' => 'ext-1',
        ...$attributes,
    ]));
}

function ledgerRejects(Closure $statement): bool
{
    try {
        DB::transaction($statement);
    } catch (QueryException) {
        return true;
    }

    return false;
}

it('records an external id under a natural key and reads it back', function () {
    $entry = ledgerEntry()->fresh();

    expect($entry->kind)->toBe(ResourceKind::Voice)
        ->and($entry->external_id)->toBe('ext-1')
        ->and($entry->key_fingerprint)->toBe(str_repeat('a', 16))
        ->and($entry->account_id)->toBe($this->account->id);
});

it('knows the three kinds the registrars use', function () {
    expect(array_map(fn (ResourceKind $kind) => $kind->value, ResourceKind::cases()))->toBe(['llm_secret', 'vendor_secret', 'voice']);
});

it('keeps one entry per account, provider, key fingerprint, kind and natural key', function () {
    ledgerEntry();

    expect(ledgerRejects(fn () => ledgerEntry(['external_id' => 'ext-2'])))->toBeTrue();
});

it('lets the same natural key exist under another key, kind, provider or account', function (array $change) {
    ledgerEntry();

    expect(ledgerRejects(fn () => ledgerEntry($change)))->toBeFalse();
})->with([
    'another key fingerprint (the account switched keys)' => [['key_fingerprint' => str_repeat('b', 16)]],
    'another kind' => [['kind' => ResourceKind::VendorSecret]],
    'another provider' => [['provider' => 'tavus']],
    'another natural key' => [['natural_key' => 'cartesia:voice-2:sonic-2']],
]);

it('lets another account hold the same entry, and never shows it', function () {
    $other = Account::factory()->create();
    $mine = ledgerEntry();
    ledgerEntry(['external_id' => 'theirs'], $other);

    expect(inAccount($this->account, fn () => ProviderResource::pluck('external_id')->all()))->toBe(['ext-1'])
        ->and(inAccount($other, fn () => ProviderResource::find($mine->id)))->toBeNull()
        ->and(fn () => ProviderResource::count())->toThrow(LogicException::class);
});

it('refuses a kind outside the three, and an account that does not exist', function () {
    $row = [
        'id' => (string) Str::ulid(), 'account_id' => $this->account->id, 'provider' => 'heygen',
        'key_fingerprint' => str_repeat('a', 16), 'kind' => 'voice', 'natural_key' => 'k', 'external_id' => 'e',
        'created_at' => now(), 'updated_at' => now(),
    ];

    expect(ledgerRejects(fn () => DB::table('provider_resources')->insert([...$row, 'kind' => 'other'])))->toBeTrue()
        ->and(ledgerRejects(fn () => DB::table('provider_resources')->insert([...$row, 'account_id' => (string) Str::ulid()])))->toBeTrue()
        ->and(ledgerRejects(fn () => DB::table('provider_resources')->insert($row)))->toBeFalse();
});

it('does not take the owner from input', function () {
    $other = Account::factory()->create();

    $entry = ledgerEntry(['account_id' => $other->id]);

    expect($entry->account_id)->toBe($this->account->id);
});
