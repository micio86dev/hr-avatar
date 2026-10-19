<?php

use App\Conversations\ConversationProviderAuth;
use App\Credentials\ProviderAuth;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ProviderCredential;

beforeEach(function () {
    $this->account = Account::factory()->create();
    foreach (['tavus', 'heygen'] as $provider) {
        config()->set("hr-avatar.platform_keys.{$provider}", null);
    }
});

/**
 * @param  array<string, mixed>  $attributes
 */
function conversationOn(string $provider, array $attributes = []): Conversation
{
    return inAccount(test()->account, fn () => Conversation::factory()->create(['provider' => $provider, ...$attributes]));
}

it('gives a provider that takes no credential a placeholder, and never looks for a key', function () {
    $auth = app(ConversationProviderAuth::class)->for(conversationOn('mock'));

    expect($auth)->toBeInstanceOf(ProviderAuth::class)->and($auth->owner)->toBe(ProviderAuth::NONE);
});

it('gives a provider that takes a credential the key of the conversation\'s account', function () {
    inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'tv-key-123456']));

    $auth = app(ConversationProviderAuth::class)->for(conversationOn('tavus'));

    expect($auth->apiKey())->toBe('tv-key-123456')->and($auth->owner)->toBe($this->account->id);
});

it('falls back to the platform key, and to nothing', function () {
    config()->set('hr-avatar.platform_keys.tavus', 'platform-key-9');
    $conversation = conversationOn('tavus');

    $platform = app(ConversationProviderAuth::class)->for($conversation);
    config()->set('hr-avatar.platform_keys.tavus', null);
    $none = app(ConversationProviderAuth::class)->for($conversation);

    expect($platform->owner)->toBe(ProviderAuth::PLATFORM)->and($none)->toBeNull();
});

it('does not use the key of another account', function () {
    inAccount(Account::factory()->create(), fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'other-key-123456']));

    expect(app(ConversationProviderAuth::class)->for(conversationOn('tavus')))->toBeNull();
});

it('keeps the placeholder from holding a real key', function () {
    $auth = ProviderAuth::none();

    expect($auth->owner)->toBe(ProviderAuth::NONE)->and(fn () => serialize($auth))->toThrow(LogicException::class);
});
