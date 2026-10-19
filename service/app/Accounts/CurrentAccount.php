<?php

namespace App\Accounts;

use Closure;
use LogicException;

/**
 * The account a request, job or command acts for. Bound per request by the authentication
 * middleware and per unit of work by runAs(); nothing else may set it.
 */
final class CurrentAccount
{
    private ?string $id = null;

    public function id(): ?string
    {
        return $this->id;
    }

    /**
     * @throws LogicException when no account is bound: an account-owned query must never run unscoped.
     */
    public function require(): string
    {
        return $this->id ?? throw new LogicException('No account is bound; an account-owned model cannot be queried or created.');
    }

    /**
     * Acts for an account while the callback runs, then restores whatever was bound before.
     */
    public static function runAs(string $accountId, Closure $callback): mixed
    {
        $current = app(self::class);
        $previous = $current->id;
        $current->id = $accountId;

        try {
            return $callback();
        } finally {
            $current->id = $previous;
        }
    }
}
