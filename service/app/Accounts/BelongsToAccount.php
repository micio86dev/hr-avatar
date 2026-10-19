<?php

namespace App\Accounts;

use Illuminate\Database\Eloquent\Model;

/**
 * Marks a model as owned by one account: queries are scoped to the bound account and the owner
 * is stamped on create. account_id is never mass-assignable and input can never choose it.
 *
 * @mixin Model
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);

        static::creating(function (Model $model): void {
            $model->setAttribute('account_id', app(CurrentAccount::class)->require());
        });
    }
}
