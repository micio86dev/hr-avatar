<?php

namespace App\Keys;

use App\Accounts\CurrentAccount;
use App\Models\ApiKey;
use Illuminate\Support\Carbon;

/**
 * Creates a key for an account. The raw key is returned to the caller once and exists nowhere
 * else: the row keeps only its hash and its identifying prefix.
 */
final class KeyIssuer
{
    /**
     * @param  list<string>  $scopes  empty for a publishable key
     * @param  list<string>|null  $allowedOrigins  normalized; publishable keys only
     * @return array{0: ApiKey, 1: string} the stored row and the raw key
     */
    public function issue(
        string $accountId,
        KeyType $type,
        KeyMode $mode,
        array $scopes,
        ?array $allowedOrigins = null,
        ?Carbon $expiresAt = null,
        ?string $rotatedFromId = null,
    ): array {
        $raw = KeyGenerator::generate($type, $mode);

        $key = CurrentAccount::runAs($accountId, fn () => ApiKey::create([
            'type' => $type,
            'mode' => $mode,
            'key_prefix' => KeyGenerator::prefixOf($raw),
            'key_hash' => KeyGenerator::hash($raw),
            'scopes' => $scopes,
            'allowed_origins' => $allowedOrigins,
            'expires_at' => $expiresAt,
            'rotated_from_id' => $rotatedFromId,
        ]));

        return [$key, $raw];
    }
}
