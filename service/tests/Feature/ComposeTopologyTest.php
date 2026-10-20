<?php

use Illuminate\Support\Facades\Process;

/**
 * The compose file as Docker resolves it (defaults applied), so the test reads the topology
 * that `docker compose up` would create rather than the YAML text.
 *
 * @return array<string, mixed>
 */
function composeConfig(): array
{
    $result = Process::path(base_path())->run(['docker', 'compose', '-f', 'docker-compose.yml', 'config', '--format', 'json']);

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

it('runs exactly the five documented services', function () {
    $services = array_keys(composeConfig()['services']);
    sort($services);

    expect($services)->toBe(['api', 'postgres', 'redis', 'scheduler', 'worker']);
});

it('publishes the documented host ports, on loopback only', function (string $service, int $published, int $target) {
    $port = composeConfig()['services'][$service]['ports'][0];

    expect((int) $port['published'])->toBe($published)
        ->and($port['target'])->toBe($target)
        ->and($port['host_ip'])->toBe('127.0.0.1');
})->with([
    'postgres' => ['postgres', 55432, 5432],
    'redis' => ['redis', 56379, 6379],
    'api' => ['api', 8100, 8000],
]);

it('starts the worker and the scheduler with the framework commands', function (string $service, string $command) {
    expect(implode(' ', composeConfig()['services'][$service]['command']))->toBe($command);
})->with([
    'worker' => ['worker', 'php artisan queue:work'],
    'scheduler' => ['scheduler', 'php artisan schedule:work'],
]);

it('shares one image across api, worker and scheduler, built once', function () {
    $services = composeConfig()['services'];

    expect($services['api']['image'])->toBe($services['worker']['image'])->toBe($services['scheduler']['image'])
        ->and($services['api'])->toHaveKey('build')
        ->and($services['worker'])->not->toHaveKey('build')
        ->and($services['scheduler'])->not->toHaveKey('build');
});

it('keeps the database in a named volume and mounts the test database init script', function () {
    $config = composeConfig();
    $targets = collect($config['services']['postgres']['volumes'])->mapWithKeys(fn (array $v) => [$v['target'] => $v['source']]);

    expect(array_keys($config['volumes']))->toBe(['hra-pgdata'])
        ->and($targets['/var/lib/postgresql/data'])->toBe('hra-pgdata')
        ->and($targets)->toHaveKey('/docker-entrypoint-initdb.d')
        ->and(file_get_contents(base_path('docker/initdb/01-create-test-db.sql')))->toContain('CREATE DATABASE hra_test');
});

it('waits for healthy dependencies before starting the application services', function (string $service) {
    $dependsOn = composeConfig()['services'][$service]['depends_on'];

    expect($dependsOn['postgres']['condition'])->toBe('service_healthy')
        ->and($dependsOn['redis']['condition'])->toBe('service_healthy');
})->with(['api', 'worker', 'scheduler']);
