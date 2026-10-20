<?php

namespace App\Accounts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on an account-owned model to the bound account, and throws when none is
 * bound. A loud failure beats a silently empty result: it cannot hide a missing authentication step.
 *
 * @implements Scope<Model>
 */
final class AccountScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('account_id'), app(CurrentAccount::class)->require());
    }
}
