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
        ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok']]);
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
    'redis down' => ['redis', ['database' => 'ok', 'redis' => 'failed']],
    'database down' => ['database', ['database' => 'failed', 'redis' => 'ok']],
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
