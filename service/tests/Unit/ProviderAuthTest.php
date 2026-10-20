<?php

use App\Credentials\ProviderAuth;

it('derives the fingerprint from the key: the first 16 hex characters of its sha256', function () {
    $auth = new ProviderAuth('tv_secret_key_value', 'acct');

    expect($auth->fingerprint)->toBe(substr(hash('sha256', 'tv_secret_key_value'), 0, 16))
        ->and($auth->fingerprint)->toMatch('/^[0-9a-f]{16}$/')
        ->and((new ProviderAuth('another-key', 'acct'))->fingerprint)->not->toBe($auth->fingerprint)
        ->and((new ProviderAuth('tv_secret_key_value', 'platform'))->fingerprint)->toBe($auth->fingerprint);
});

it('hands the key to the one caller that must send it, and names the owner', function () {
    $auth = new ProviderAuth('tv_secret_key_value', 'acct_1');

    expect($auth->apiKey())->toBe('tv_secret_key_value')->and($auth->owner)->toBe('acct_1');
});

it('keeps the key out of every dump and out of JSON', function () {
    $auth = new ProviderAuth('tv_secret_key_value', 'acct_1');

    ob_start();
    var_dump($auth);
    print_r($auth);
    $dumped = ob_get_clean();

    expect($dumped)->not->toContain('tv_secret_key_value')->toContain('[redacted]')
        ->and(json_encode($auth))->not->toContain('tv_secret_key_value')
        ->and(json_encode($auth))->toContain($auth->fingerprint);
});

it('refuses to be serialized, so a queued job can never carry the key', function () {
    $auth = new ProviderAuth('tv_secret_key_value', 'acct_1');

    expect(fn () => serialize($auth))->toThrow(LogicException::class, 'cannot be serialized');
});

it('refuses an empty key', function () {
    expect(fn () => new ProviderAuth('', 'acct_1'))->toThrow(InvalidArgumentException::class);
});
