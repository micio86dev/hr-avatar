<?php

use Illuminate\Support\Facades\Process;

/*
 * `composer install` runs `package:discover`, which boots the application; the application refuses to boot outside
 * testing without HRA_JWT_SECRET, and a fresh checkout has none. The script therefore runs discovery as `testing`:
 * discovery reads the installed packages and writes the manifest, and uses no setting of the environment it runs in.
 */
function composerScripts(): array
{
    return json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['scripts'];
}

it('runs package discovery as the testing environment, so a fresh checkout installs without a secret', function () {
    $steps = composerScripts()['post-autoload-dump'];

    $putenv = array_search('@putenv APP_ENV=testing', $steps, true);
    $discover = array_search('@php artisan package:discover --ansi', $steps, true);

    expect($putenv)->toBeInt()->and($discover)->toBeInt()->and($putenv)->toBeLessThan($discover);
});

it('discovers packages with no JWT secret when the environment is testing, and refuses to boot without one otherwise', function () {
    $discover = fn (string $environment) => Process::path(base_path())
        ->env(['APP_ENV' => $environment, 'HRA_JWT_SECRET' => '', 'DB_PORT' => (string) config('database.connections.pgsql.port')])
        ->run([PHP_BINARY, 'artisan', 'package:discover']);

    expect($discover('testing')->successful())->toBeTrue()
        ->and($discover('local')->successful())->toBeFalse();
});
