<?php

use Illuminate\Support\Facades\Http;

it('refuses an outbound request that has no fake', function () {
    expect(fn () => Http::get('https://example.test/ping'))
        ->toThrow(RuntimeException::class, 'without a matching fake');
});

it('lets a faked request through', function () {
    Http::fake(['https://example.test/ping' => Http::response(['ok' => true])]);

    expect(Http::get('https://example.test/ping')->json('ok'))->toBeTrue();
});
