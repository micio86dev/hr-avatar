<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->databasePort = config('database.connections.pgsql.port');
    $this->redisPort = config('database.redis.default.port');
});

// Restore the real ports before the framework tears the connection down.
afterEach(function () {
    config()->set('database.connections.pgsql.port', $this->databasePort);
    config()->set('database.redis.default.port', $this->redisPort);
    DB::purge();
    Redis::purge();
});

function breakDatabase(): void
{
    config()->set('database.connections.pgsql.port', 1);
    DB::purge();
}

function breakRedis(): void
{
    config()->set('database.redis.default.port', 1);
    Redis::purge();
}

it('reports liveness without touching the database or Redis', function () {
    Redis::spy();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $response = $this->get('/v1/health');

    $response->assertOk();
    expect($response->getContent())->toBe('{"status":"ok"}');
    expect($queries)->toBe([]);
    Redis::shouldNotHaveReceived('connection');
});

it('survives a dependency failure for liveness', function () {
    breakDatabase();
    breakRedis();

    $this->get('/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('reports readiness when both dependencies answer', function () {
    $this->get('/v1/health/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok', 'public_base_url' => 'ok']]);
});

it('reports readiness failure with the failing dependency named', function (string $broken, array $checks) {
    $broken === 'redis' ? breakRedis() : breakDatabase();

    $response = $this->get('/v1/health/ready');

    $response->assertStatus(503);
    expect($response->headers->get('Content-Type'))->toBe('application/problem+json');
    expect($response->json('code'))->toBe('service_unavailable')
        ->and($response->json('checks'))->toBe($checks)
        ->and($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
})->with([
    'redis down' => ['redis', ['database' => 'ok', 'redis' => 'failed', 'public_base_url' => 'ok']],
    'database down' => ['database', ['database' => 'failed', 'redis' => 'ok', 'public_base_url' => 'ok']],
]);

it('exposes no versions, hostnames or connection strings when a dependency fails', function () {
    breakRedis();

    $content = $this->get('/v1/health/ready')->getContent();

    expect($content)
        ->not->toContain('127.0.0.1')
        ->not->toContain('postgres')
        ->not->toContain('refused')
        ->not->toContain('phpredis');
});

it('does not localize health values', function () {
    $this->get('/v1/health', ['Accept-Language' => 'it'])->assertExactJson(['status' => 'ok']);
});

it('rejects an unsupported method on health with 405', function () {
    $this->delete('/v1/health')->assertStatus(405)->assertHeader('Allow');
});

it('logs which dependency failed, never why', function () {
    breakRedis();
    Log::spy();

    $this->get('/v1/health/ready')->assertStatus(503);

    Log::shouldHaveReceived('warning')->once()->with('Readiness check failed.', ['dependency' => 'redis']);
});

// Ruling 55: a provider that calls back needs an address to call, so readiness fails without one.
describe('the public base url', function () {
    it('fails readiness when it is unset and a registered provider calls back', function (?string $value) {
        config()->set('hr-avatar.public_base_url', $value);

        $response = $this->get('/v1/health/ready');

        $response->assertStatus(503);
        expect($response->json('code'))->toBe('service_unavailable')
            ->and($response->json('checks'))->toBe(['database' => 'ok', 'redis' => 'ok', 'public_base_url' => 'failed']);
    })->with([
        'unset' => [null],
        'empty' => [''],
        'blank' => ['   '],
        'no scheme' => ['hra.example.test'],
        'not http' => ['ftp://hra.example.test'],
        'no host' => ['https://'],
    ]);

    it('accepts an http or https address, with or without a trailing slash', function (string $value) {
        config()->set('hr-avatar.public_base_url', $value);

        $this->get('/v1/health/ready')->assertOk()->assertJsonPath('checks.public_base_url', 'ok');
    })->with(['https://hra.example.test', 'https://hra.example.test/', 'http://localhost:8100']);

    it('is not asked for when no registered provider calls back', function () {
        config()->set('hr-avatar.public_base_url', null);
        config()->set('hr-avatar.providers', ['mock' => config('hr-avatar.providers.mock'), 'heygen' => config('hr-avatar.providers.heygen')]);

        $this->get('/v1/health/ready')->assertOk()->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok']]);
    });

    it('logs which check failed and never the value', function () {
        config()->set('hr-avatar.public_base_url', 'not-an-address');
        Log::spy();

        $this->get('/v1/health/ready')->assertStatus(503);

        Log::shouldHaveReceived('warning')->once()->with('Readiness check failed.', ['dependency' => 'public_base_url']);
    });

    it('leaves liveness alone', function () {
        config()->set('hr-avatar.public_base_url', null);

        $this->get('/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
    });
});
