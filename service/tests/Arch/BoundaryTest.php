<?php

use Tests\Arch\VocabularyScanner;

/**
 * The service carries no host-product vocabulary: a host passes an opaque external_ref and
 * free-form metadata, nothing more. The architecture files themselves are exempt.
 */
it('contains no host-product vocabulary in source, config, schema, routes or the SDK', function () {
    $roots = array_filter([
        base_path('app'),
        base_path('config'),
        base_path('database'),
        base_path('routes'),
        dirname(base_path()).'/sdk/src',
    ], 'is_dir');

    expect($roots)->toHaveCount(5);

    $findings = (new VocabularyScanner)->scan($roots);

    expect($findings)->toBe([], collect($findings)
        ->map(fn (array $f) => "{$f['file']}:{$f['line']} contains \"{$f['term']}\"")
        ->implode("\n"));
});
