<?php

namespace App\Keys;

use App\Accounts\CurrentAccount;
use App\Models\Account;
use App\Models\ApiKey;

/**
 * Creates a key for an account. The raw key is returned to the caller once and exists nowhere
 * else: the row keeps only its hash and its identifying prefix.
 */
final class KeyIssuer
{
    /**
     * @param  list<string>  $scopes
     * @return array{0: ApiKey, 1: string} the stored row and the raw key
     */
    public function issue(Account $account, KeyType $type, KeyMode $mode, array $scopes): array
    {
        $raw = KeyGenerator::generate($type, $mode);

        $key = CurrentAccount::runAs($account->id, fn () => ApiKey::create([
            'type' => $type,
            'mode' => $mode,
            'key_prefix' => KeyGenerator::prefixOf($raw),
            'key_hash' => KeyGenerator::hash($raw),
            'scopes' => $scopes,
        ]));

        return [$key, $raw];
    }
}
