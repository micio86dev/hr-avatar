<?php

use Illuminate\Support\Facades\Process;

/*
 * Defaults a deployment inherits when it sets nothing: a database link that is encrypted outside a developer's
 * machine, and a connect and a read bound on both stores so that an unreachable one fails the readiness check
 * (and a request) in seconds instead of holding a worker for the operating system's default of a minute or more.
 */

/** The value of the pgsql `sslmode` that config/database.php resolves to in the given environment. */
function sslmodeIn(string $environment, ?string $override = null): string
{
    $code = 'require "vendor/autoload.php"; echo (require "config/database.php")["connections"]["pgsql"]["sslmode"];';
    $env = ['APP_ENV' => $environment, 'DB_SSLMODE' => $override ?? false];

    return trim(Process::path(base_path())->env($env)->run([PHP_BINARY, '-r', $code])->throw()->output());
}

it('requires an encrypted database link everywhere except local development and tests', function (string $environment, string $expected) {
    expect(sslmodeIn($environment))->toBe($expected);
})->with([
    'production' => ['production', 'require'],
    'staging' => ['staging', 'require'],
    'local' => ['local', 'prefer'],
    'testing' => ['testing', 'prefer'],
]);

it('lets a deployment choose the mode', function () {
    expect(sslmodeIn('production', 'verify-full'))->toBe('verify-full');
});

it('bounds the time to connect to PostgreSQL', function () {
    $timeout = config('database.connections.pgsql.options')[PDO::ATTR_TIMEOUT] ?? null;

    expect($timeout)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(10);
});

it('bounds the time to connect to Redis and to wait for it, on both connections', function (string $connection) {
    $config = config("database.redis.{$connection}");

    expect($config['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(5)
        ->and($config['read_timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(10);
})->with(['default', 'cache']);
