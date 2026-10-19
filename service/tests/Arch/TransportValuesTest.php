<?php

use Tests\Arch\SourceScanner;

/**
 * The browser SDK loads a transport by the name the service returns, and has exactly three: a new value on the
 * service side would reach a browser that cannot join it.
 */
it('names only the transports the SDK can load', function () {
    // JoinMaterial only carries the adapter's value to the answer; the adapters are where one is chosen.
    $values = (new SourceScanner)->transportValues([base_path('app')], ['/app/AvatarProviders/JoinMaterial.php']);

    expect($values)->not->toBeEmpty()
        ->and(array_diff($values, ['daily', 'liveavatar', 'mock']))->toBe([])
        ->and(array_unique($values))->toEqualCanonicalizing(['daily', 'liveavatar', 'mock']);
});

it('finds a transport written as a named argument or an array key', function () {
    $file = sys_get_temp_dir().'/transport-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($file, "<?php\n\nnew JoinMaterial(transport: 'zoom');\n\$a = ['transport' => 'webrtc', 'other' => 'x'];\n\$b = \$transport === 'ignored';\n");

    $values = (new SourceScanner)->transportValues([$file]);
    unlink($file);

    expect($values)->toBe(['zoom', 'webrtc']);
});

it('does not exempt an adapter: a transport it chooses must be a literal the list can check', function () {
    $adapters = glob(base_path('app/AvatarProviders/*/*Provider.php')) ?: [];

    expect($adapters)->not->toBeEmpty()
        ->and((new SourceScanner)->transportValues($adapters))->not->toContain('<not a literal>');
});

it('reports a transport that is not a literal, so it cannot slip past the list', function () {
    $file = sys_get_temp_dir().'/transport-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($file, "<?php\n\nnew JoinMaterial(transport: \$name);\n\$a = ['transport' => \$this->transport];\n");

    $values = (new SourceScanner)->transportValues([$file]);
    unlink($file);

    expect($values)->toBe(['<not a literal>', '<not a literal>']);
});

it('is the set the SDK registers', function () {
    $sdk = (string) file_get_contents(dirname(base_path()).'/sdk/src/transports/index.ts');

    foreach (['daily', 'liveavatar', 'mock'] as $name) {
        expect($sdk)->toContain($name);
    }
})->skip(fn () => ! is_file(dirname(base_path()).'/sdk/src/transports/index.ts'), 'no SDK sources beside the service');
