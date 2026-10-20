<?php

use App\Keys\KeyMode;
use App\Models\Account;
use App\Models\AvatarConfig;

// KeyMode documents that `live` and `test` label a key space and never partition data. This pins it.
it('shows a live key and a test key of one account the same resources', function () {
    $account = Account::factory()->create();
    [, $live] = issueKey($account, ['scopes' => ['*']], mode: KeyMode::Live);
    [, $test] = issueKey($account, ['scopes' => ['*']], mode: KeyMode::Test);
    $config = inAccount($account, fn () => AvatarConfig::factory()->create());

    $seenByLive = $this->getJson('/v1/avatar-configs', ['Authorization' => "Bearer {$live}"])->assertOk()->json('data.*.id');
    $seenByTest = $this->getJson('/v1/avatar-configs', ['Authorization' => "Bearer {$test}"])->assertOk()->json('data.*.id');
    $keysSeenByLive = $this->getJson('/v1/api-keys', ['Authorization' => "Bearer {$live}"])->json('data.*.id');
    $keysSeenByTest = $this->getJson('/v1/api-keys', ['Authorization' => "Bearer {$test}"])->json('data.*.id');

    expect($seenByLive)->toBe([$config->id])->and($seenByTest)->toBe([$config->id])
        ->and($keysSeenByLive)->toEqualCanonicalizing($keysSeenByTest)->and($keysSeenByLive)->toHaveCount(2);
});

it('does not let a test key reach another account\'s resources either', function () {
    $account = Account::factory()->create();
    $other = Account::factory()->create();
    [, $test] = issueKey($account, ['scopes' => ['*']], mode: KeyMode::Test);
    inAccount($other, fn () => AvatarConfig::factory()->create());

    expect($this->getJson('/v1/avatar-configs', ['Authorization' => "Bearer {$test}"])->assertOk()->json('data'))->toBe([]);
});
