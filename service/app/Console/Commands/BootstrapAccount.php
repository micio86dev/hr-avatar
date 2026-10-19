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
use Illuminate\Support\Facades\DB;

#[Signature('hra:bootstrap-account {name : The account name} {--mode=live : Key space of the first key (live or test)}')]
#[Description('Create an account and its first secret key (scope *); the key is printed once')]
class BootstrapAccount extends Command
{
    use ReadsInput;

    public function handle(KeyIssuer $issuer): int
    {
        $mode = KeyMode::tryFrom($this->stringOption('mode', '') ?? '');

        if ($mode === null) {
            $this->error('The mode must be "live" or "test".');

            return self::FAILURE;
        }

        $name = trim($this->stringArgument('name'));

        if ($name === '') {
            $this->error('The account name must not be blank.');

            return self::FAILURE;
        }

        [$account, $rawKey] = DB::transaction(function () use ($issuer, $mode, $name) {
            $account = Account::create(['name' => $name]);

            return [$account, $issuer->issue($account->id, KeyType::Secret, $mode, [Scope::Wildcard->value])[1]];
        });

        $this->info("Account {$account->id} created.");
        $this->line($rawKey);
        $this->comment('The key above is shown once and cannot be recovered. Store it now.');

        return self::SUCCESS;
    }
}
