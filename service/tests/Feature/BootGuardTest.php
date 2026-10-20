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

// A rate limit or a duration read from an empty or non-numeric environment variable casts to 0, and a budget of 0 turns
// every request into a 429 (or every create into a 422). The application refuses to start instead of serving that.
it('refuses to boot with a rate limit or duration below its minimum, naming the variable and not the value', function (string $key, string $variable, mixed $value) {
    config()->set($key, $value);

    try {
        bootProviderIn('production', str_repeat('k', 32));
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain($variable)->not->toContain('-5');

        return;
    }

    $this->fail("Boot accepted {$key} = ".var_export($value, true));
})->with(function () {
    $settings = [
        'hr-avatar.rate_limits.secret' => 'HRA_RATE_LIMIT_SECRET',
        'hr-avatar.rate_limits.publishable' => 'HRA_RATE_LIMIT_PUBLISHABLE',
        'hr-avatar.rate_limits.preview' => 'HRA_RATE_LIMIT_PREVIEW',
        'hr-avatar.max_duration_ceiling' => 'HRA_MAX_DURATION_CEILING',
        'hr-avatar.release_delay_seconds' => 'HRA_RELEASE_DELAY_SECONDS',
    ];

    foreach ($settings as $key => $variable) {
        foreach (['zero' => 0, 'negative' => -5] as $label => $value) {
            yield "{$variable} {$label}" => [$key, $variable, $value];
        }
    }
});

it('refuses a release delay below its lower bound and a duration ceiling below the shortest conversation', function (string $key, int $value, string $variable) {
    config()->set($key, $value);

    bootProviderIn('production', str_repeat('k', 32));
})->with([
    'a release delay of 4 seconds' => ['hr-avatar.release_delay_seconds', 4, 'HRA_RELEASE_DELAY_SECONDS'],
    'a ceiling of 29 seconds' => ['hr-avatar.max_duration_ceiling', 29, 'HRA_MAX_DURATION_CEILING'],
])->throws(RuntimeException::class);

it('boots with the smallest allowed values and with the defaults', function () {
    bootProviderIn('production', str_repeat('k', 32));
    config()->set('hr-avatar.rate_limits', ['secret' => 1, 'publishable' => 1, 'preview' => 1, 'failed_auth' => 30]);
    config()->set('hr-avatar.release_delay_seconds', 5);
    config()->set('hr-avatar.max_duration_ceiling', 30);

    bootProviderIn('production', str_repeat('k', 32));

    expect(config('hr-avatar.rate_limits.secret'))->toBe(1);
});

it('checks the limits in testing too, where the defaults are valid', function () {
    config()->set('hr-avatar.rate_limits.secret', 0);

    bootProviderIn('testing', null);
})->throws(RuntimeException::class, 'HRA_RATE_LIMIT_SECRET');
