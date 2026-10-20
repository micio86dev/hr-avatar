<?php

namespace App\Keys;

use App\Accounts\AccountScope;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Models\ApiKey;
use Illuminate\Support\Facades\DB;

/**
 * Turns a presented key into its row. The order is the security property:
 *
 *  1. the format and type are checked as strings, so a malformed key never reaches the database;
 *  2. the one row with the stored prefix and the key's hash is read (the hash is unique) and compared with hash_equals;
 *  3. only a key that matched is told apart as expired or revoked.
 *
 * An unknown or wrong key is always `unauthenticated`, so the resolver cannot be used to learn
 * whether a key ever existed. It runs before any account is bound, hence the unscoped lookup.
 */
final class KeyResolver
{
    /** At most one last_used_at write per key in this window. */
    private const LAST_USED_WINDOW_SECONDS = 60;

    /**
     * @throws ApiProblem unauthenticated, key_expired or key_revoked
     */
    public function resolve(string $rawKey, KeyType $expected): ApiKey
    {
        $parsed = KeyGenerator::parse($rawKey);

        if ($parsed === null || $parsed->type !== $expected) {
            throw new ApiProblem(ErrorCode::Unauthenticated);
        }

        $key = $this->matching($rawKey) ?? throw new ApiProblem(ErrorCode::Unauthenticated);

        if ($key->revoked_at !== null) {
            throw new ApiProblem(ErrorCode::KeyRevoked);
        }

        if ($key->expires_at !== null && $key->expires_at->isPast()) {
            throw new ApiProblem(ErrorCode::KeyExpired);
        }

        $this->touch($key);

        return $key;
    }

    private function matching(string $rawKey): ?ApiKey
    {
        $hash = KeyGenerator::hash($rawKey);

        // The hash is unique and indexed, so at most one row is read however many keys share the prefix; the
        // prefix keeps the lookup on the narrow index, and hash_equals stays the judge of the match.
        $candidate = ApiKey::withoutGlobalScope(AccountScope::class)
            ->where('key_prefix', KeyGenerator::prefixOf($rawKey))
            ->where('key_hash', $hash)
            ->first();

        return $candidate !== null && hash_equals($candidate->key_hash, $hash) ? $candidate : null;
    }

    private function touch(ApiKey $key): void
    {
        if ($key->last_used_at !== null && $key->last_used_at->gt(now()->subSeconds(self::LAST_USED_WINDOW_SECONDS))) {
            return;
        }

        $now = now();
        DB::table('api_keys')->where('account_id', $key->account_id)->where('id', $key->id)->update(['last_used_at' => $now]);
        $key->last_used_at = $now;
    }
}
