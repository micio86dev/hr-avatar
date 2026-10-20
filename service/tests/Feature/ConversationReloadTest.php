<?php

use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
 * Review rv24: `reload()` reads the row back under the account scope, where `refresh()` reads by id alone. A row that
 * is not visible under the current account is an error, not a silent stale copy: a lifecycle decision made on stale
 * state is worse than a failed request, and every caller holds the account binding (the key middleware, `runAs`).
 */
beforeEach(function () {
    $this->account = Account::factory()->create();
    $config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
    $this->conversation = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id]));
});

it('reads the current row back under the account that owns it', function () {
    Conversation::withoutGlobalScopes()->whereKey($this->conversation->id)->update(['external_ref' => 'changed-elsewhere']);

    $reloaded = inAccount($this->account, fn () => $this->conversation->reload());

    expect($reloaded)->toBe($this->conversation)->and($reloaded->external_ref)->toBe('changed-elsewhere');
});

it('fails, rather than returning a stale copy, when the row is not visible under the current account', function () {
    $other = Account::factory()->create();

    expect(fn () => inAccount($other, fn () => $this->conversation->reload()))->toThrow(ModelNotFoundException::class);
});

it('fails when the row is gone', function () {
    Conversation::withoutGlobalScopes()->whereKey($this->conversation->id)->delete();

    expect(fn () => inAccount($this->account, fn () => $this->conversation->reload()))->toThrow(ModelNotFoundException::class);
});
