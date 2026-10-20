<?php

use App\Keys\ClientSecret;
use App\Keys\KeyType;
use App\Models\Account;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->account = Account::factory()->create();
    [, $this->pk] = issueKey($this->account, type: KeyType::Publishable);

    Route::middleware(['api', 'auth.client'])->post('/v1/client/conversations/{conversation}/probe', fn () => ['ok' => true]);
});

function bound(string $routeConversation, string $tokenConversation, Account $tokenAccount, string $pk)
{
    $token = (new ClientSecret)->mint($tokenConversation, $tokenAccount->id, now(), 1800)->token;

    return test()->postJson("/v1/client/conversations/{$routeConversation}/probe", [], [
        'X-HRA-Publishable-Key' => $pk,
        'Authorization' => "Bearer {$token}",
        'Origin' => 'https://app.example.com',
    ]);
}

it('accepts a token for the conversation in the URL', function () {
    bound('conv_one', 'conv_one', $this->account, $this->pk)->assertOk();
});

it('refuses a token minted for another conversation of the same account', function (string $route, string $token) {
    $response = bound($route, $token, $this->account, $this->pk);

    $response->assertStatus(403);
    expect($response->json('code'))->toBe('forbidden');
})->with([
    'another id' => ['conv_two', 'conv_one'],
    'a different case' => ['CONV_ONE', 'conv_one'],
    'a prefix of it' => ['conv_one_and_more', 'conv_one'],
]);

it('refuses a token minted for another account\'s conversation', function () {
    $other = Account::factory()->create();

    $response = bound('conv_one', 'conv_one', $other, $this->pk);

    $response->assertStatus(403);
    expect($response->json('code'))->toBe('forbidden');
});

it('does not let a token of account A be used with the publishable key of account B', function () {
    [, $foreignPk] = issueKey(Account::factory()->create(), type: KeyType::Publishable);

    $response = bound('conv_one', 'conv_one', $this->account, $foreignPk);

    $response->assertStatus(403);
    expect($response->json('code'))->toBe('forbidden');
});
