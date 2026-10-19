<?php

use App\Models\Account;

it('never serializes the key hash', function () {
    [$key, $raw] = issueKey(Account::factory()->create());

    expect($key->toArray())->not->toHaveKey('key_hash')
        ->and($key->toJson())->not->toContain($key->key_hash)->not->toContain($raw)
        ->and(json_encode($key))->not->toContain($key->key_hash);
});

it('keeps the fields a listing needs', function () {
    [$key] = issueKey(Account::factory()->create(), ['name' => 'ci']);

    expect($key->toArray())->toHaveKeys(['id', 'type', 'mode', 'key_prefix', 'scopes', 'allowed_origins'])
        ->and($key->toArray()['name'])->toBe('ci');
});
