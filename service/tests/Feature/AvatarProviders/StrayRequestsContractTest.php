<?php

use App\AvatarProviders\Mock\MockProvider;
use Illuminate\Support\Facades\Http;

/**
 * Adapter tests may only use faked HTTP: an unfaked request is a failure, never a network call,
 * and no live provider key is needed to run the suite.
 */
it('fails a provider call that has no fake instead of reaching the network', function () {
    expect(fn () => Http::withToken('not-a-real-key')->post('https://api.provider.example.test/v1/sessions', ['a' => 1]))
        ->toThrow(RuntimeException::class, 'without a matching fake');
});

it('fails a call whose URL the fake does not cover', function () {
    Http::fake(['https://api.provider.example.test/known' => Http::response(['ok' => true])]);

    expect(Http::get('https://api.provider.example.test/known')->json('ok'))->toBeTrue()
        ->and(fn () => Http::get('https://api.provider.example.test/other'))->toThrow(RuntimeException::class);
});

it('is in force for the adapter namespace: the shipped adapters make no request without a fake', function () {
    Http::fake();

    foreach (array_column(config('hr-avatar.providers'), 'adapter') as $adapter) {
        expect(app($adapter))->toBeObject();
    }
    expect(app(MockProvider::class)->ingestion()->value)->toBe('none');
    Http::assertNothingSent();
});
