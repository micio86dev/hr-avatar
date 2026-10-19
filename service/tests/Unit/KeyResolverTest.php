<?php

use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Keys\KeyGenerator;
use App\Keys\KeyMode;
use App\Keys\KeyResolver;
use App\Keys\KeyType;
use App\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function resolveError(string $raw, KeyType $type = KeyType::Secret): ?ErrorCode
{
    try {
        app(KeyResolver::class)->resolve($raw, $type);
    } catch (ApiProblem $problem) {
        return $problem->errorCode;
    }

    return null;
}

beforeEach(function () {
    $this->account = Account::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

it('authenticates a valid key and returns its row', function () {
    [$key, $raw] = issueKey($this->account);

    $resolved = app(KeyResolver::class)->resolve($raw, KeyType::Secret);

    expect($resolved->id)->toBe($key->id)->and($resolved->account_id)->toBe($this->account->id);
});

it('authenticates a publishable key when a publishable key is expected', function () {
    [$key, $raw] = issueKey($this->account, type: KeyType::Publishable, mode: KeyMode::Test);

    expect(app(KeyResolver::class)->resolve($raw, KeyType::Publishable)->id)->toBe($key->id);
});

it('does not authenticate a key that shares the prefix but not the remainder', function () {
    [, $raw] = issueKey($this->account);
    $forged = substr($raw, 0, -1).($raw[-1] === 'a' ? 'b' : 'a');

    expect(KeyGenerator::prefixOf($raw) === KeyGenerator::prefixOf($forged))->toBeTrue()
        ->and(resolveError($forged))->toBe(ErrorCode::Unauthenticated);
});

it('answers a malformed key without touching the database', function (string $raw) {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    expect(resolveError($raw))->toBe(ErrorCode::Unauthenticated)->and($queries)->toBe([]);
})->with(['hra_sk_prod_abc', '', 'not a key at all']);

it('answers a key of the other type as unauthenticated, without a lookup', function () {
    [, $publishable] = issueKey($this->account, type: KeyType::Publishable);
    [, $secret] = issueKey($this->account);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    expect(resolveError($publishable, KeyType::Secret))->toBe(ErrorCode::Unauthenticated)
        ->and(resolveError($secret, KeyType::Publishable))->toBe(ErrorCode::Unauthenticated)
        ->and($queries)->toBe([]);
});

it('reveals nothing about a well-formed key that does not exist', function () {
    expect(resolveError('hra_sk_live_'.str_repeat('0', 96)))->toBe(ErrorCode::Unauthenticated);
});

it('reports an expired key only after the hash matched', function () {
    [, $raw] = issueKey($this->account, ['expires_at' => now()->subSecond()]);

    expect(resolveError($raw))->toBe(ErrorCode::KeyExpired);
});

it('reports a revoked key only after the hash matched', function () {
    [, $raw] = issueKey($this->account, ['revoked_at' => now()->subMinute()]);

    expect(resolveError($raw))->toBe(ErrorCode::KeyRevoked);
});

it('reports a key that is both revoked and expired as revoked', function () {
    [, $raw] = issueKey($this->account, ['revoked_at' => now()->subMinute(), 'expires_at' => now()->subHour()]);

    expect(resolveError($raw))->toBe(ErrorCode::KeyRevoked);
});

it('still authenticates a key whose expiry is in the future', function () {
    [, $raw] = issueKey($this->account, ['expires_at' => now()->addMinute()]);

    expect(resolveError($raw))->toBeNull();
});

it('does not reach another account\'s key through the resolver being account-unaware', function () {
    $other = Account::factory()->create();
    [$key, $raw] = issueKey($other);

    // No account is bound at this point: resolving a key is how the account becomes known.
    expect(app(KeyResolver::class)->resolve($raw, KeyType::Secret)->account_id)->toBe($other->id);
});

it('stamps last_used_at on first use and at most once per 60 seconds afterwards', function () {
    Carbon::setTestNow('2026-10-19 12:00:00');
    [$key, $raw] = issueKey($this->account);
    expect($key->last_used_at)->toBeNull();

    app(KeyResolver::class)->resolve($raw, KeyType::Secret);
    $first = DB::table('api_keys')->where('id', $key->id)->value('last_used_at');

    Carbon::setTestNow('2026-10-19 12:00:30');
    app(KeyResolver::class)->resolve($raw, KeyType::Secret);
    $second = DB::table('api_keys')->where('id', $key->id)->value('last_used_at');

    Carbon::setTestNow('2026-10-19 12:01:01');
    app(KeyResolver::class)->resolve($raw, KeyType::Secret);
    $third = DB::table('api_keys')->where('id', $key->id)->value('last_used_at');

    expect($first)->toBe('2026-10-19 12:00:00')
        ->and($second)->toBe('2026-10-19 12:00:00')
        ->and($third)->toBe('2026-10-19 12:01:01');
});
