<?php

use App\Accounts\CurrentAccount;
use App\Credentials\CredentialResolver;
use App\Credentials\ProviderAuth;
use App\Models\Account;
use App\Models\ProviderCredential;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->resolver = new CredentialResolver;
    config()->set('hr-avatar.platform_keys', ['tavus' => null, 'heygen' => null, 'cartesia' => null, 'elevenlabs' => null]);
});

function storeFor(Account $account, string $provider, string $key): void
{
    inAccount($account, fn () => ProviderCredential::create(['provider' => $provider, 'api_key' => $key]));
}

it('prefers the account\'s own key over the platform key', function () {
    storeFor($this->account, 'tavus', 'account-key-123456');
    config()->set('hr-avatar.platform_keys.tavus', 'platform-key-123456');

    $auth = $this->resolver->for($this->account->id, 'tavus');

    expect($auth)->toBeInstanceOf(ProviderAuth::class)
        ->and($auth->apiKey())->toBe('account-key-123456')
        ->and($auth->owner)->toBe($this->account->id);
});

it('falls back to the platform key', function () {
    config()->set('hr-avatar.platform_keys.tavus', 'platform-key-123456');

    $auth = $this->resolver->for($this->account->id, 'tavus');

    expect($auth?->apiKey())->toBe('platform-key-123456')->and($auth->owner)->toBe(ProviderAuth::PLATFORM);
});

it('finds nothing when neither exists, and treats an empty platform key as unset', function (?string $platform) {
    config()->set('hr-avatar.platform_keys.tavus', $platform);

    expect($this->resolver->for($this->account->id, 'tavus'))->toBeNull();
})->with([null, '']);

it('never uses one account\'s key for another', function () {
    storeFor($this->account, 'tavus', 'account-a-key-123456');
    $other = Account::factory()->create();

    expect($this->resolver->for($other->id, 'tavus'))->toBeNull();

    config()->set('hr-avatar.platform_keys.tavus', 'platform-key-123456');
    expect($this->resolver->for($other->id, 'tavus')?->apiKey())->toBe('platform-key-123456');
});

it('answers for the account it is asked about, whichever account is bound, and restores the binding', function () {
    storeFor($this->account, 'tavus', 'account-a-key-123456');
    $other = Account::factory()->create();

    $auth = inAccount($other, function () {
        $auth = $this->resolver->for($this->account->id, 'tavus');
        expect(app(CurrentAccount::class)->id())->not->toBeNull();

        return $auth;
    });

    expect($auth?->apiKey())->toBe('account-a-key-123456');
});

it('keeps providers apart', function () {
    storeFor($this->account, 'heygen', 'heygen-key-123456');

    expect($this->resolver->for($this->account->id, 'tavus'))->toBeNull()
        ->and($this->resolver->for($this->account->id, 'heygen')?->apiKey())->toBe('heygen-key-123456');
});

it('resolves only the configured credential providers', function () {
    config()->set('hr-avatar.platform_keys.acme', 'platform-key-123456');
    storeFor($this->account, 'acme', 'account-key-123456');

    expect($this->resolver->for($this->account->id, 'acme'))->toBeNull()
        ->and($this->resolver->for($this->account->id, 'mock'))->toBeNull();
});

// Documented in CredentialResolver: an account key that cannot be decrypted (a rotated APP_KEY, a corrupt row) is not
// skipped. Falling back to the platform key would bill the platform for a call the account meant to pay for.
it('throws for an undecryptable account key and never falls back to the platform key', function () {
    storeFor($this->account, 'tavus', 'account-key-123456');
    config()->set('hr-avatar.platform_keys.tavus', 'platform-key-123456');
    DB::table('provider_credentials')->where('account_id', $this->account->id)->update(['api_key' => 'not-a-valid-ciphertext']);

    $resolve = fn () => $this->resolver->for($this->account->id, 'tavus');

    expect($resolve)->toThrow(DecryptException::class);
});
