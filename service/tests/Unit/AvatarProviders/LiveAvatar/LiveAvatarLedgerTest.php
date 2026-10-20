<?php

use App\AvatarProviders\LiveAvatar\LiveAvatarApi;
use App\AvatarProviders\LiveAvatar\LiveAvatarLedger;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\ResourceKind;
use App\Credentials\ProviderAuth;
use App\Models\Account;
use App\Models\ProviderResource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->ledger = new LiveAvatarLedger(new LiveAvatarApi);
    $this->account = Account::factory()->create();
    $this->auth = new ProviderAuth('la-key-one-123456', ProviderAuth::PLATFORM);
});

function laRows(Account $account): int
{
    return inAccount($account, fn () => ProviderResource::query()->count());
}

it('finds nothing before anything is recorded, then the recorded id', function () {
    expect($this->ledger->find($this->account->id, $this->auth, ResourceKind::Voice, 'cartesia:fp:v1'))->toBeNull();

    $this->ledger->record($this->account->id, $this->auth, ResourceKind::Voice, 'cartesia:fp:v1', 'voice-a');

    expect($this->ledger->find($this->account->id, $this->auth, ResourceKind::Voice, 'cartesia:fp:v1'))->toBe('voice-a');
});

it('stores the row for the provider, the key fingerprint, the kind and the account', function () {
    $this->ledger->record($this->account->id, $this->auth, ResourceKind::LlmSecret, 'nk', 'sec-1');

    $row = inAccount($this->account, fn () => ProviderResource::query()->sole());

    expect($row->provider)->toBe('heygen')
        ->and($row->key_fingerprint)->toBe($this->auth->fingerprint)
        ->and($row->kind)->toBe(ResourceKind::LlmSecret)
        ->and($row->natural_key)->toBe('nk')
        ->and($row->external_id)->toBe('sec-1')
        ->and($row->account_id)->toBe($this->account->id);
});

it('never answers from another account, another provider key or another kind', function () {
    $this->ledger->record($this->account->id, $this->auth, ResourceKind::Voice, 'nk', 'mine');
    $other = Account::factory()->create();

    expect($this->ledger->find($other->id, $this->auth, ResourceKind::Voice, 'nk'))->toBeNull()
        ->and($this->ledger->find($this->account->id, new ProviderAuth('la-key-two-654321', ProviderAuth::PLATFORM), ResourceKind::Voice, 'nk'))->toBeNull()
        ->and($this->ledger->find($this->account->id, $this->auth, ResourceKind::VendorSecret, 'nk'))->toBeNull()
        ->and($this->ledger->find($this->account->id, $this->auth, ResourceKind::Voice, 'other'))->toBeNull();
});

it('keeps the first entry when two writers race for one natural key, and says which id won', function () {
    $first = $this->ledger->record($this->account->id, $this->auth, ResourceKind::Voice, 'nk', 'winner');
    $second = $this->ledger->record($this->account->id, $this->auth, ResourceKind::Voice, 'nk', 'loser');

    expect($first)->toBe('winner')
        ->and($second)->toBe('winner')
        ->and(laRows($this->account))->toBe(1);
});

it('forgets one entry and leaves the others', function () {
    $this->ledger->record($this->account->id, $this->auth, ResourceKind::Voice, 'a', '1');
    $this->ledger->record($this->account->id, $this->auth, ResourceKind::Voice, 'b', '2');

    $this->ledger->forget($this->account->id, $this->auth, ResourceKind::Voice, 'a');

    expect($this->ledger->find($this->account->id, $this->auth, ResourceKind::Voice, 'a'))->toBeNull()
        ->and($this->ledger->find($this->account->id, $this->auth, ResourceKind::Voice, 'b'))->toBe('2');
});

it('creates a secret once and then answers from the ledger without a call', function () {
    Http::fake(['api.liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec-1', 'secret_name' => 'x']])]);
    $create = fn () => $this->ledger->secret($this->account->id, $this->auth, ResourceKind::LlmSecret, 'nk', 'OPENAI_API_KEY', 'hra-llm-nk', 'sk-the-value');

    expect($create())->toBe('sec-1')->and($create())->toBe('sec-1');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->data() === ['secret_type' => 'OPENAI_API_KEY', 'secret_value' => 'sk-the-value', 'secret_name' => 'hra-llm-nk']);
});

it('creates a secret per account, even for the same provider key and value', function () {
    Http::fake(['api.liveavatar.com/v1/secrets' => Http::sequence()
        ->push(['data' => ['id' => 'sec-a']])
        ->push(['data' => ['id' => 'sec-b']])]);
    $other = Account::factory()->create();

    $mine = $this->ledger->secret($this->account->id, $this->auth, ResourceKind::LlmSecret, 'nk', 'OPENAI_API_KEY', 'n', 'v');
    $theirs = $this->ledger->secret($other->id, $this->auth, ResourceKind::LlmSecret, 'nk', 'OPENAI_API_KEY', 'n', 'v');

    expect($mine)->toBe('sec-a')->and($theirs)->toBe('sec-b');
});

it('stores nothing and says so when the provider refuses or answers without an id', function (Closure $answer) {
    Http::fake(['api.liveavatar.com/v1/secrets' => $answer]);

    expect(fn () => $this->ledger->secret($this->account->id, $this->auth, ResourceKind::LlmSecret, 'nk', 'OPENAI_API_KEY', 'n', 'v'))
        ->toThrow(ProviderException::class);
    expect(laRows($this->account))->toBe(0);
})->with([
    '500' => [fn () => Http::response(['message' => 'boom'], 500)],
    'no data.id' => [fn () => Http::response(['code' => 1000, 'id' => 'top-level', 'data' => []])],
    'empty data.id' => [fn () => Http::response(['data' => ['id' => '']])],
]);

it('removes the duplicate secret it created when another writer recorded one first', function () {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => function () {
            // Another process commits its entry between our lookup and our insert.
            $this->ledger->record($this->account->id, $this->auth, ResourceKind::LlmSecret, 'nk', 'sec-winner');

            return Http::response(['data' => ['id' => 'sec-loser']]);
        },
        'api.liveavatar.com/v1/secrets/sec-loser' => Http::response([]),
    ]);

    $id = $this->ledger->secret($this->account->id, $this->auth, ResourceKind::LlmSecret, 'nk', 'OPENAI_API_KEY', 'n', 'v');

    expect($id)->toBe('sec-winner')
        ->and(laRows($this->account))->toBe(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://api.liveavatar.com/v1/secrets/sec-loser');
});

it('still returns the winner when removing its duplicate secret fails', function (Closure $removal) {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => function () {
            $this->ledger->record($this->account->id, $this->auth, ResourceKind::LlmSecret, 'nk', 'sec-winner');

            return Http::response(['data' => ['id' => 'sec-loser']]);
        },
        'api.liveavatar.com/v1/secrets/sec-loser' => $removal,
    ]);

    expect($this->ledger->secret($this->account->id, $this->auth, ResourceKind::LlmSecret, 'nk', 'OPENAI_API_KEY', 'n', 'v'))->toBe('sec-winner');
})->with([
    'a 500' => [fn () => Http::response([], 500)],
    'a timeout' => [fn () => throw new ConnectionException('timeout')],
]);
