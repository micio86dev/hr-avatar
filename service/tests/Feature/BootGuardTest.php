<?php

use App\Providers\AppServiceProvider;

function bootProviderIn(string $environment, ?string $secret): void
{
    app()->instance('env', $environment);
    config()->set('hr-avatar.jwt_secret', $secret);

    (new AppServiceProvider(app()))->boot();
}

it('refuses to boot outside testing when the JWT secret is missing', function (?string $secret) {
    bootProviderIn('production', $secret);
})->with([
    'unset' => [null],
    'empty' => [''],
])->throws(RuntimeException::class, 'HRA_JWT_SECRET');

it('refuses to boot outside testing when the JWT secret is short, without echoing it', function () {
    $secret = 'sixteen-byte-key!';

    try {
        bootProviderIn('production', $secret);
    } catch (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain('HRA_JWT_SECRET')
            ->not->toContain($secret);

        return;
    }

    $this->fail('Boot did not refuse a short JWT secret.');
});

it('applies the guard in every non-testing environment', function (string $environment) {
    bootProviderIn($environment, 'too-short');
})->with(['local', 'staging', 'production'])->throws(RuntimeException::class, 'HRA_JWT_SECRET');

it('boots in testing without a usable secret', function () {
    bootProviderIn('testing', null);

    expect(config('hr-avatar.jwt_secret'))->toBeNull();
});

it('boots outside testing with a 32-byte secret', function () {
    bootProviderIn('production', str_repeat('k', 32));

    expect(strlen(config('hr-avatar.jwt_secret')))->toBe(32);
});
