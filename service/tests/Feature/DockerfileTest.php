<?php

function dockerfile(): string
{
    return file_get_contents(base_path('Dockerfile'));
}

it('builds in two named stages from the pinned PHP image', function () {
    preg_match_all('/^FROM (\S+) AS (\S+)$/m', dockerfile(), $stages, PREG_SET_ORDER);

    expect(array_map(fn (array $s) => [$s[1], $s[2]], $stages))->toBe([
        ['php:8.5.8-fpm-alpine', 'builder'],
        ['php:8.5.8-fpm-alpine', 'runtime'],
    ]);
});

it('pins the tool images it copies from', function () {
    preg_match_all('/^COPY --from=(\S+)/m', dockerfile(), $matches);
    $images = array_filter($matches[1], fn (string $image) => str_contains($image, ':'));

    expect($images)->toContain('mlocati/php-extension-installer:2.11.12', 'composer:2.10.2');
    foreach ($images as $image) {
        expect($image)->not->toEndWith(':latest');
    }
});

it('installs the runtime extensions', function (string $extension) {
    $runtime = substr(dockerfile(), strpos(dockerfile(), 'AS runtime'));

    expect($runtime)->toMatch("/install-php-extensions[^\n]*\\b{$extension}\\b/");
})->with(['pdo_pgsql', 'redis', 'pcntl']);

it('has no frontend build step', function () {
    expect(dockerfile())->not->toMatch('/\b(npm|pnpm|yarn|bun|node|vite)\b/i');
});

it('installs without scripts, because package discovery boots the app and needs the signing secret', function () {
    expect(dockerfile())->toContain('composer install --no-dev --no-scripts')
        ->and(dockerfile())->toContain('composer dump-autoload --optimize --no-dev --no-scripts');
});

it('runs as a non-root user and checks the versioned health endpoint', function () {
    expect(dockerfile())->toContain("USER appuser\n")
        ->and(dockerfile())->toContain('curl -f http://localhost:8000/v1/health')
        ->and(dockerfile())->toContain('expose_php = Off');
});

it('starts through an entrypoint that execs its command, so worker and scheduler reuse the image', function () {
    expect(file_get_contents(base_path('docker/entrypoint.sh')))->toContain('exec "$@"')
        ->and(dockerfile())->toContain('ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]');
});
