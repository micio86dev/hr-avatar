<?php

use App\Keys\KeyGenerator;
use App\Keys\KeyMode;
use App\Keys\KeyType;
use App\Keys\Scope;

it('generates keys in the documented format', function (KeyType $type, KeyMode $mode, string $pattern) {
    expect(KeyGenerator::generate($type, $mode))->toMatch($pattern);
})->with([
    'secret live' => [KeyType::Secret, KeyMode::Live, '/^hra_sk_live_[0-9a-f]{96}$/D'],
    'secret test' => [KeyType::Secret, KeyMode::Test, '/^hra_sk_test_[0-9a-f]{96}$/D'],
    'publishable live' => [KeyType::Publishable, KeyMode::Live, '/^hra_pk_live_[0-9a-f]{48}$/D'],
    'publishable test' => [KeyType::Publishable, KeyMode::Test, '/^hra_pk_test_[0-9a-f]{48}$/D'],
]);

it('never generates the same key twice', function () {
    expect(KeyGenerator::generate(KeyType::Secret, KeyMode::Live))
        ->not->toBe(KeyGenerator::generate(KeyType::Secret, KeyMode::Live));
});

it('hashes with sha256 and keeps the hash independent of anything else', function () {
    $key = KeyGenerator::generate(KeyType::Secret, KeyMode::Live);

    expect(KeyGenerator::hash($key))->toBe(hash('sha256', $key))->toHaveLength(64)
        ->and(KeyGenerator::hash($key))->not->toBe(KeyGenerator::hash(KeyGenerator::generate(KeyType::Secret, KeyMode::Live)));
});

it('derives the stored prefix from the marker and the first 8 hex characters', function () {
    $secret = 'hra_sk_live_'.str_repeat('ab12cd34', 12);
    $publishable = 'hra_pk_test_'.str_repeat('0f', 24);

    expect(KeyGenerator::prefixOf($secret))->toBe('hra_sk_live_ab12cd34')
        ->and(KeyGenerator::prefixOf($publishable))->toBe('hra_pk_test_0f0f0f0f');
});

it('has no prefix for a key it cannot parse', function () {
    expect(fn () => KeyGenerator::prefixOf('hra_sk_prod_abc'))->toThrow(InvalidArgumentException::class);
});

it('parses the type and mode of a well-formed key', function (string $key, KeyType $type, KeyMode $mode) {
    $parsed = KeyGenerator::parse($key);

    expect($parsed?->type)->toBe($type)->and($parsed?->mode)->toBe($mode);
})->with([
    'secret live' => ['hra_sk_live_'.str_repeat('ab12cd34', 12), KeyType::Secret, KeyMode::Live],
    'publishable test' => ['hra_pk_test_'.str_repeat('0f', 24), KeyType::Publishable, KeyMode::Test],
]);

it('refuses to parse a malformed key', function (string $key) {
    expect(KeyGenerator::parse($key))->toBeNull();
})->with([
    'unknown mode' => ['hra_sk_prod_abc'],
    'empty' => [''],
    'wrong secret length' => ['hra_sk_live_'.str_repeat('a', 95)],
    'wrong publishable length' => ['hra_pk_live_'.str_repeat('a', 96)],
    'uppercase hex' => ['hra_sk_live_'.str_repeat('A', 96)],
    'trailing newline' => ['hra_pk_live_'.str_repeat('a', 48)."\n"],
    'other product prefix' => ['sk_live_'.str_repeat('a', 96)],
]);

it('lists the documented scopes', function () {
    expect(array_map(fn (Scope $s) => $s->value, Scope::cases()))->toBe([
        '*',
        'avatar_configs:read', 'avatar_configs:write',
        'credentials:manage',
        'llm_models:read',
        'conversations:read', 'conversations:write',
        'webhook_endpoints:manage',
        'api_keys:manage',
    ]);
});

it('lets the wildcard satisfy every scope and a specific scope only itself', function () {
    foreach (Scope::cases() as $required) {
        expect(Scope::grants(['*'], $required))->toBeTrue();
    }

    expect(Scope::grants(['conversations:read'], Scope::ConversationsRead))->toBeTrue()
        ->and(Scope::grants(['conversations:read'], Scope::ConversationsWrite))->toBeFalse()
        ->and(Scope::grants([], Scope::ConversationsRead))->toBeFalse()
        ->and(Scope::grants(['*'], Scope::Wildcard))->toBeTrue()
        ->and(Scope::grants(['conversations:read'], Scope::Wildcard))->toBeFalse();
});
