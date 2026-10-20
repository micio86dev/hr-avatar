<?php

/*
 * Railway configuration as code. The files describe how the one image runs as three services; nothing in
 * this repository applies them (see DocsBoundaryTest: no script, workflow or command here talks to Railway).
 */
function railwayFile(string $name): array
{
    $path = base_path($name);

    expect(file_exists($path))->toBeTrue("service/{$name} does not exist");

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('has the three files, each valid JSON that names the Railway schema', function (string $name) {
    expect(railwayFile($name)['$schema'])->toBe('https://railway.com/railway.schema.json');
})->with(['railway.json', 'railway.worker.json', 'railway.scheduler.json']);

describe('the api service', function () {
    it('builds the Dockerfile', function () {
        expect(railwayFile('railway.json')['build'])->toBe(['builder' => 'DOCKERFILE', 'dockerfilePath' => 'Dockerfile']);
    });

    it('migrates, then seeds the language model catalogue, before the new version takes traffic', function () {
        expect(railwayFile('railway.json')['deploy']['preDeployCommand'])->toBe([
            'php artisan migrate --force',
            'php artisan db:seed --class=LlmModelSeeder --force',
        ]);
    });

    it('is healthy once the liveness endpoint answers, and restarts on failure', function () {
        $deploy = railwayFile('railway.json')['deploy'];

        expect($deploy['healthcheckPath'])->toBe('/v1/health')
            ->and($deploy['healthcheckTimeout'])->toBeInt()->toBeGreaterThanOrEqual(30)
            ->and($deploy['restartPolicyType'])->toBe('ON_FAILURE')
            ->and($deploy['restartPolicyMaxRetries'])->toBeInt()
            ->and($deploy)->not->toHaveKey('startCommand');
    });
});

describe('the worker and the scheduler', function () {
    it('run the same image with their own start command', function (string $file, string $command) {
        $config = railwayFile($file);

        expect($config['build'])->toBe(['builder' => 'DOCKERFILE', 'dockerfilePath' => 'Dockerfile'])
            ->and($config['deploy']['startCommand'])->toBe($command)
            ->and($config['deploy']['restartPolicyType'])->toBe('ALWAYS');
    })->with([
        'worker' => ['railway.worker.json', 'php artisan queue:work'],
        'scheduler' => ['railway.scheduler.json', 'php artisan schedule:work'],
    ]);

    it('neither migrate (the api does, once per deploy) nor wait for an HTTP healthcheck they cannot answer', function (string $file) {
        expect(railwayFile($file)['deploy'])->not->toHaveKey('preDeployCommand')->not->toHaveKey('healthcheckPath');
    })->with(['railway.worker.json', 'railway.scheduler.json']);
});

it('sets no cron schedule and no multi-region config on any of the three services', function (string $file) {
    expect(railwayFile($file)['deploy'])->not->toHaveKey('cronSchedule')->not->toHaveKey('multiRegionConfig');
})->with(['railway.json', 'railway.worker.json', 'railway.scheduler.json']);

it('pins the scheduler to one replica', function () {
    expect(railwayFile('railway.scheduler.json')['deploy']['numReplicas'])->toBe(1);
});

it('uses the same start commands as the local compose file', function () {
    $compose = (string) file_get_contents(base_path('docker-compose.yml'));

    expect($compose)->toContain('["php", "artisan", "queue:work"]')->toContain('["php", "artisan", "schedule:work"]');
});

it('uses only keys the Railway schema defines (read from railway.com/railway.schema.json on 2026-10-19), so a typo is not silently ignored', function (string $file) {
    $allowed = [
        '$schema' => true,
        'build' => ['builder', 'watchPatterns', 'buildCommand', 'dockerfilePath', 'nixpacksConfigPath', 'nixpacksPlan', 'nixpacksVersion', 'railpackVersion'],
        'deploy' => ['startCommand', 'preDeployCommand', 'preDeployTimeoutSeconds', 'numReplicas', 'healthcheckPath', 'healthcheckTimeout', 'sleepApplication', 'runtime', 'registryCredentials', 'restartPolicyType', 'restartPolicyMaxRetries', 'cronSchedule', 'region', 'multiRegionConfig', 'limitOverride', 'requiredMountPath', 'overlapSeconds', 'drainingSeconds', 'ipv6EgressEnabled'],
    ];
    $config = railwayFile($file);

    expect(array_diff(array_keys($config), array_keys($allowed)))->toBe([])
        ->and(array_diff(array_keys($config['build']), $allowed['build']))->toBe([])
        ->and(array_diff(array_keys($config['deploy']), $allowed['deploy']))->toBe([]);
})->with(['railway.json', 'railway.worker.json', 'railway.scheduler.json']);
