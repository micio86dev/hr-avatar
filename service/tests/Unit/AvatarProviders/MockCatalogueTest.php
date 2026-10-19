<?php

use App\AvatarProviders\Mock\MockProvider;
use App\Credentials\ProviderAuth;

it('lists nothing and makes no call', function () {
    Http::fake();

    expect((new MockProvider)->catalogue('avatar', new ProviderAuth('unused-key', ProviderAuth::PLATFORM)))->toBe([]);
    Http::assertNothingSent();
});
