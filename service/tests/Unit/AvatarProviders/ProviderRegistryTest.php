<?php

use App\AvatarProviders\AvatarProvider;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\Mock\MockProvider;
use App\AvatarProviders\ProviderRegistry;
use App\AvatarProviders\UnknownProvider;
use Illuminate\Support\Facades\Http;
use Tests\Support\AvatarProviders\CallbackStubProvider;
use Tests\Support\AvatarProviders\PollStubProvider;

/**
 * Three registered providers, one per ingestion mode, with the same shape as the real registry.
 */
beforeEach(function () {
    config()->set('hr-avatar.providers', [
        'alpha' => ['adapter' => CallbackStubProvider::class, 'credential' => true],
        'beta' => ['adapter' => PollStubProvider::class, 'credential' => true, 'event_key_prefix' => 'betaprefix'],
        'mock' => ['adapter' => MockProvider::class, 'credential' => false],
    ]);
    $this->registry = new ProviderRegistry;
});

it('ships with the Mock provider registered, and its data lives in config only', function () {
    $shipped = (require base_path('config/hr-avatar.php'))['providers'];

    expect($shipped)->toHaveKey('mock')
        ->and($shipped['mock'])->toBe(['adapter' => MockProvider::class, 'credential' => false]);
});

it('resolves each id to an adapter that implements the contract', function () {
    foreach (['alpha', 'beta', 'mock'] as $id) {
        expect($this->registry->get($id))->toBeInstanceOf(AvatarProvider::class);
    }
    expect($this->registry->get('mock'))->toBeInstanceOf(MockProvider::class);
});

it('lists the registered ids', function () {
    expect($this->registry->ids())->toBe(['alpha', 'beta', 'mock']);
});

it('rejects an unknown id with a domain error, before any adapter is built or any request made', function (string $id) {
    Http::fake();

    expect(fn () => $this->registry->get($id))->toThrow(UnknownProvider::class)
        ->and(fn () => $this->registry->requiresCredential($id))->toThrow(UnknownProvider::class)
        ->and(fn () => $this->registry->eventKeyPrefix($id))->toThrow(UnknownProvider::class);
    Http::assertNothingSent();
})->with(['acme', '', 'ALPHA', 'constructor', '__proto__']);

it('reports how each adapter\'s events arrive', function () {
    expect($this->registry->get('alpha')->ingestion())->toBe(Ingestion::Callback)
        ->and($this->registry->get('beta')->ingestion())->toBe(Ingestion::Poll)
        ->and($this->registry->get('mock')->ingestion())->toBe(Ingestion::None);
});

it('selects the providers of one ingestion mode, so no code needs a provider name', function () {
    expect($this->registry->withIngestion(Ingestion::Callback))->toBe(['alpha'])
        ->and($this->registry->withIngestion(Ingestion::Poll))->toBe(['beta'])
        ->and($this->registry->withIngestion(Ingestion::None))->toBe(['mock']);
});

it('says whether a provider needs a credential', function () {
    expect($this->registry->requiresCredential('alpha'))->toBeTrue()
        ->and($this->registry->requiresCredential('mock'))->toBeFalse();
});

it('keys provider events by the id unless the entry names another prefix', function () {
    expect($this->registry->eventKeyPrefix('alpha'))->toBe('alpha')
        ->and($this->registry->eventKeyPrefix('beta'))->toBe('betaprefix');
});

it('builds an adapter through the container, so it can take dependencies', function () {
    expect($this->registry->get('alpha'))->not->toBe($this->registry->get('alpha'));
});

// Review rv10 R3-005: whether a provider takes a key is written twice in the shipped config; the two must not drift.
it('lists as credential providers exactly the providers that need a key and the voice vendors that are not providers', function () {
    $shipped = require base_path('config/hr-avatar.php');
    $undeclared = array_keys(array_filter($shipped['providers'], fn (array $provider) => ! array_key_exists('credential', $provider)));
    expect($undeclared)->toBe([], 'every shipped provider must say whether it takes a credential');

    $needKey = array_keys(array_filter($shipped['providers'], fn (array $provider) => ($provider['credential'] ?? false) === true));
    $vendorsOnly = array_values(array_diff(array_keys($shipped['voice_vendors']), array_keys($shipped['providers'])));

    expect($shipped['credential_providers'])->toEqualCanonicalizing([...$needKey, ...$vendorsOnly]);
});
