<?php

use App\Credentials\LlmCredentialResolver;
use App\Models\Account;
use App\Models\LlmCredential;
use App\Models\LlmModel;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->model = LlmModel::factory()->create(['provider' => 'gemini']);
    $this->resolver = new LlmCredentialResolver;
});

function storeLlmKey(Account $account, string $provider, string $key): void
{
    inAccount($account, fn () => LlmCredential::create(['llm_provider' => $provider, 'api_key' => $key]));
}

it('returns the account\'s key for the model\'s provider', function () {
    storeLlmKey($this->account, 'gemini', 'gemini-key-123456');

    expect($this->resolver->for($this->account->id, $this->model))->toBe('gemini-key-123456');
});

it('returns null when the account has no key for that provider', function () {
    storeLlmKey($this->account, 'other-vendor', 'other-key-123456');

    expect($this->resolver->for($this->account->id, $this->model))->toBeNull();
});

it('has no platform fallback', function () {
    config()->set('hr-avatar.platform_keys.gemini', 'platform-key-123456');
    config()->set('hr-avatar.platform_keys.google', 'platform-key-123456');

    expect($this->resolver->for($this->account->id, $this->model))->toBeNull();
});

it('never uses one account\'s key for another', function () {
    storeLlmKey($this->account, 'gemini', 'gemini-key-123456');

    expect($this->resolver->for(Account::factory()->create()->id, $this->model))->toBeNull();
});

it('resolves for a disabled model too, because the credential belongs to the provider', function () {
    storeLlmKey($this->account, 'gemini', 'gemini-key-123456');
    $disabled = LlmModel::factory()->create(['provider' => 'gemini', 'enabled' => false]);

    expect($this->resolver->for($this->account->id, $disabled))->toBe('gemini-key-123456');
});
