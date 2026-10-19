<?php

function workflow(): string
{
    return file_get_contents(dirname(base_path()).'/.github/workflows/service.yml');
}

function position(string $needle): int
{
    $position = strpos(workflow(), $needle);

    expect($position)->not->toBeFalse("The workflow has no \"{$needle}\" step");

    return $position;
}

it('pins every action to a version tag', function () {
    preg_match_all('/^\s*(?:- )?uses:\s*(\S+)$/m', workflow(), $matches);

    expect($matches[1])->not->toBeEmpty();
    foreach ($matches[1] as $action) {
        expect($action)->toMatch('/^[\w.-]+\/[\w.-]+@v\d+(\.\d+){0,2}$/');
    }
});

it('defines the service and openapi jobs', function () {
    expect(workflow())->toMatch('/^  service:$/m')->toMatch('/^  openapi:$/m');
});

it('runs the service job against PostgreSQL 17 and Redis 8', function () {
    expect(workflow())->toContain('image: pgvector/pgvector:0.8.0-pg17')->toContain('image: redis:8.0-alpine');
});

it('runs style, static analysis, migrations and the covered suite in that order', function () {
    $steps = array_map('position', [
        'vendor/bin/pint --test',
        'vendor/bin/phpstan analyse',
        'php artisan migrate --force',
        'php artisan test --coverage --min=90',
    ]);

    expect($steps)->toBe(collect($steps)->sort()->values()->all());
});

it('exports the OpenAPI document, fails on drift, then lints it', function () {
    $steps = array_map('position', [
        'php artisan scramble:export --path=openapi.json',
        'git diff --exit-code -- service/openapi.json',
        'bun run lint',
    ]);

    expect($steps)->toBe(collect($steps)->sort()->values()->all());
});

it('sets up Bun before any step that uses it', function () {
    expect(position('oven-sh/setup-bun@v2'))->toBeLessThan(position('bun install --frozen-lockfile'))
        ->and(position('bun install --frozen-lockfile'))->toBeLessThan(position('bun run lint'));
});

it('boots the application with a dummy signing secret of at least 32 bytes', function () {
    preg_match('/^\s*HRA_JWT_SECRET:\s*(\S+)$/m', workflow(), $match);

    expect(strlen($match[1] ?? ''))->toBeGreaterThanOrEqual(32);
});

it('does not read repository secrets or push anything', function () {
    expect(workflow())->not->toContain('secrets.')->not->toMatch('/git push|docker push|railway/i');
});
