<?php

use App\AvatarProviders\Tavus\TavusLanguage;

it('maps the two supported locales to the spelled-out form Tavus expects', function (string $locale, string $wire) {
    expect(TavusLanguage::forWire($locale))->toBe($wire);
})->with([
    'italian' => ['it', 'italian'],
    'english' => ['en', 'english'],
]);

// Tavus accepts a wrong value and ignores it silently: substituting a default for an unmapped
// locale would turn it into a confidently wrong one, so it passes through and can be noticed.
it('passes an unmapped value through unchanged', function (string $locale) {
    expect(TavusLanguage::forWire($locale))->toBe($locale);
})->with(['fr', 'de', 'pt']);
