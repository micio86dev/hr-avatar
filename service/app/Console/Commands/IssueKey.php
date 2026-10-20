<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsInput;
use App\Keys\KeyIssuer;
use App\Keys\KeyMode;
use App\Keys\KeyType;
use App\Keys\Scope;
use App\Models\Account;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hra:issue-key {account_id : The account that owns the key} {--mode=live : Key space (live or test)} {--scopes= : Comma-separated scopes (default *)}')]
#[Description('Issue an additional secret key for an existing account (lockout recovery); the key is printed once')]
class IssueKey extends Command
{
    use ReadsInput;

    public function handle(KeyIssuer $issuer): int
    {
        $mode = KeyMode::tryFrom($this->stringOption('mode', '') ?? '');

        if ($mode === null) {
            $this->error('The mode must be "live" or "test".');

            return self::FAILURE;
        }

        $scopes = array_values(array_unique(array_map('trim', explode(',', $this->stringOption('scopes', Scope::Wildcard->value) ?? Scope::Wildcard->value))));

        foreach ($scopes as $scope) {
            if (Scope::tryFrom($scope) === null) {
                $this->error('Unknown scope: '.($scope === '' ? '(empty)' : $scope));

                return self::FAILURE;
            }
        }

        $account = Account::query()->whereKey($this->stringArgument('account_id'))->first();

        if ($account === null) {
            $this->error('No account has that id.');

            return self::FAILURE;
        }

        $this->line($issuer->issue($account->id, KeyType::Secret, $mode, $scopes)[1]);
        $this->comment('The key above is shown once and cannot be recovered. Store it now.');

        return self::SUCCESS;
    }
}
