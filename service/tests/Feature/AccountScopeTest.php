<?php

use App\Accounts\AccountScope;
use App\Accounts\CurrentAccount;
use App\Models\Account;
use App\Models\ApiKey;

function keyFor(Account $account, array $attributes = []): ApiKey
{
    return inAccount($account, fn () => ApiKey::factory()->create($attributes));
}

beforeEach(function () {
    $this->accountA = Account::factory()->create(['name' => 'Account A']);
    $this->accountB = Account::factory()->create(['name' => 'Account B']);
});

it('shows an account only its own rows', function () {
    $own = keyFor($this->accountA);
    $foreign = keyFor($this->accountB);

    inAccount($this->accountA, function () use ($own, $foreign) {
        expect(ApiKey::query()->pluck('id')->all())->toBe([$own->id])
            ->and(ApiKey::find($foreign->id))->toBeNull();
    });
    inAccount($this->accountB, fn () => expect(ApiKey::query()->pluck('id')->all())->toBe([$foreign->id]));
});

it('cannot update or delete another account\'s row', function () {
    $foreign = keyFor($this->accountB, ['name' => 'untouched']);

    inAccount($this->accountA, function () use ($foreign) {
        expect(ApiKey::query()->whereKey($foreign->id)->update(['name' => 'hijacked']))->toBe(0)
            ->and(ApiKey::query()->whereKey($foreign->id)->delete())->toBe(0);
    });

    expect(inAccount($this->accountB, fn () => ApiKey::find($foreign->id)->name))->toBe('untouched');
});

it('fails closed when no account is bound', function () {
    keyFor($this->accountA);
    keyFor($this->accountB);

    expect(fn () => ApiKey::query()->get())->toThrow(LogicException::class)
        ->and(fn () => ApiKey::count())->toThrow(LogicException::class);
});

it('refuses to create an account-owned row with no account bound', function () {
    expect(fn () => ApiKey::factory()->create())->toThrow(LogicException::class);
});

it('stamps the bound account and ignores an account_id in the input', function () {
    $key = inAccount($this->accountA, fn () => ApiKey::factory()->create(['account_id' => $this->accountB->id]));

    expect($key->account_id)->toBe($this->accountA->id);
});

it('does not let mass assignment set the account', function () {
    $key = inAccount($this->accountA, fn () => (new ApiKey)->fill(['account_id' => $this->accountB->id, 'name' => 'x']));

    expect($key->account_id)->toBeNull()->and($key->name)->toBe('x');
});

it('binds an account for the duration of runAs and restores the previous one', function () {
    $current = app(CurrentAccount::class);

    expect($current->id())->toBeNull();

    CurrentAccount::runAs($this->accountA->id, function () use ($current) {
        expect($current->id())->toBe($this->accountA->id);

        CurrentAccount::runAs($this->accountB->id, fn () => expect($current->id())->toBe($this->accountB->id));

        expect($current->id())->toBe($this->accountA->id);
    });

    expect($current->id())->toBeNull();
});

it('restores the previous account when the callback throws', function () {
    expect(fn () => CurrentAccount::runAs($this->accountA->id, fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect(app(CurrentAccount::class)->id())->toBeNull();
});

it('lets a job read across accounts only by removing the scope explicitly', function () {
    $foreign = keyFor($this->accountB);

    expect(ApiKey::withoutGlobalScope(AccountScope::class)->find($foreign->id)?->id)->toBe($foreign->id);
});

it('does not scope the accounts table itself', function () {
    expect(Account::query()->count())->toBe(2);
});
