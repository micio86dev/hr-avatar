<?php

/**
 * The pinned dependency set is the Dependency Resolution Policy made executable:
 * a constraint that is loosened, replaced or removed fails here.
 */
function composerManifest(): array
{
    return json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('pins the runtime constraints', function (string $package, string $constraint) {
    expect(composerManifest()['require'][$package] ?? null)->toBe($constraint);
})->with([
    'php' => ['php', '^8.5'],
    'laravel' => ['laravel/framework', '^13.8'],
    'scramble' => ['dedoc/scramble', '^0.13'],
    'jwt' => ['lcobucci/jwt', '^5.6'],
]);

it('pins the development constraints', function (string $package, string $constraint) {
    expect(composerManifest()['require-dev'][$package] ?? null)->toBe($constraint);
})->with([
    'pest' => ['pestphp/pest', '^4.0'],
    'pest laravel plugin' => ['pestphp/pest-plugin-laravel', '^4.0'],
    'larastan' => ['larastan/larastan', '^3'],
    'pint' => ['laravel/pint', '^1.27'],
]);
