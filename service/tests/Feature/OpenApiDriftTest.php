<?php

use Dedoc\Scramble\Generator;

/*
 * The committed openapi.json is the published contract. Regenerate it with
 * `composer run openapi:export` whenever a route, request or response changes.
 */
it('matches the committed openapi.json', function () {
    $path = base_path('openapi.json');

    expect(file_exists($path))->toBeTrue('service/openapi.json is not committed');

    $committed = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $generated = json_decode(json_encode(app(Generator::class)(), JSON_THROW_ON_ERROR), true);

    expect($generated)->toEqual($committed);
});

it('is generated for the product title and a relative server', function () {
    $committed = json_decode(file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($committed['info']['title'])->toBe('HR-Avatar')
        ->and($committed['servers'])->toBe([['url' => '/v1']])
        ->and($committed['openapi'])->toStartWith('3.1');
});
