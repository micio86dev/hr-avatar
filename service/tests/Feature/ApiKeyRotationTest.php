<?php

use App\Keys\KeyMode;
use App\Keys\KeyType;
use App\Models\Account;
use App\Models\ApiKey;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00', 'UTC'));
    $this->account = Account::factory()->create();
    [$this->caller, $this->raw] = issueKey($this->account, ['scopes' => ['*']]);
});

function manage(string $method, string $path, array $body = [], ?string $raw = null): TestResponse
{
    return test()->{$method}("/v1/api-keys{$path}", $body, ['Authorization' => 'Bearer '.($raw ?? test()->raw)]);
}

function target(array $attributes = [], KeyType $type = KeyType::Secret, KeyMode $mode = KeyMode::Live): array
{
    return issueKey(test()->account, $attributes + ['scopes' => ['conversations:read']], $type, $mode);
}

function authenticatesWith(string $raw): TestResponse
{
    return test()->getJson('/v1/api-keys', ['Authorization' => "Bearer {$raw}"]);
}

function fresh(ApiKey $key): ApiKey
{
    return ApiKey::withoutGlobalScopes()->findOrFail($key->id);
}

describe('revoke', function () {
    it('revokes a key, which is refused from then on', function () {
        [$key, $raw] = target(['scopes' => ['*']]);

        $response = manage('postJson', "/{$key->id}/revoke")->assertOk();

        expect($response->json('revoked_at'))->toBe('2026-10-19T12:00:00Z')
            ->and($response->json('id'))->toBe($key->id)
            ->and($response->json())->not->toHaveKeys(['key', 'key_hash']);
        $refused = authenticatesWith($raw);
        $refused->assertStatus(401);
        expect($refused->json('code'))->toBe('key_revoked');
    });

    it('is idempotent and keeps the original revocation time', function () {
        [$key] = target();
        manage('postJson', "/{$key->id}/revoke")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-19 15:00:00', 'UTC'));
        $again = manage('postJson', "/{$key->id}/revoke")->assertOk();

        expect($again->json('revoked_at'))->toBe('2026-10-19T12:00:00Z');
    });

    it('may revoke the key it is called with, because lockout recovery is the issue-key command', function () {
        manage('postJson', "/{$this->caller->id}/revoke")->assertOk();

        expect(authenticatesWith($this->raw)->json('code'))->toBe('key_revoked');
    });
});

describe('rotate', function () {
    it('creates a successor of the same shape and keeps the old key for the overlap', function () {
        [$old, $oldRaw] = target(['scopes' => ['conversations:read', 'conversations:write']]);

        $response = manage('postJson', "/{$old->id}/rotate")->assertCreated();
        $new = $response->json();

        expect($new['key'])->toMatch('/^hra_sk_live_[0-9a-f]{96}$/')
            ->and($new['id'])->not->toBe($old->id)
            ->and($new['rotated_from_id'])->toBe($old->id)
            ->and($new['type'])->toBe('secret')
            ->and($new['mode'])->toBe('live')
            ->and($new['scopes'])->toBe(['conversations:read', 'conversations:write'])
            ->and($new['expires_at'])->toBeNull()
            ->and(array_keys($new))->not->toContain('key_hash')
            ->and(fresh($old)->expires_at?->toIso8601ZuluString())->toBe('2026-10-19T13:00:00Z');

        // 403 (not 401): both keys still authenticate, they merely lack api_keys:manage.
        Carbon::setTestNow(now()->addMinutes(10));
        expect(authenticatesWith($oldRaw)->status())->toBe(403)->and(authenticatesWith($new['key'])->status())->toBe(403);
    });

    it('lets both keys authenticate during the overlap and only the new one after it', function () {
        [$old, $oldRaw] = target(['scopes' => ['*']]);
        $newRaw = manage('postJson', "/{$old->id}/rotate")->json('key');

        Carbon::setTestNow(now()->addMinutes(10));
        authenticatesWith($oldRaw)->assertOk();
        authenticatesWith($newRaw)->assertOk();

        Carbon::setTestNow(now()->addMinutes(51));
        $expired = authenticatesWith($oldRaw);
        $expired->assertStatus(401);
        expect($expired->json('code'))->toBe('key_expired');
        authenticatesWith($newRaw)->assertOk();
    });

    it('revokes the old key at once when the overlap is zero', function () {
        [$old, $oldRaw] = target(['scopes' => ['*']]);

        manage('postJson', "/{$old->id}/rotate", ['overlap_seconds' => 0])->assertCreated();

        expect(fresh($old)->revoked_at?->toIso8601ZuluString())->toBe('2026-10-19T12:00:00Z');
        expect(authenticatesWith($oldRaw)->json('code'))->toBe('key_revoked');
    });

    it('honours an overlap from 0 to 604800 seconds and refuses anything else', function (mixed $overlap, int $status) {
        [$old] = target();

        manage('postJson', "/{$old->id}/rotate", ['overlap_seconds' => $overlap])->assertStatus($status);
    })->with([
        'one second' => [1, 201],
        'the maximum' => [604800, 201],
        'negative' => [-1, 422],
        'beyond the maximum' => [604801, 422],
        'text' => ['soon', 422],
        'decimal' => [1.5, 422],
    ]);

    it('never lets a rotation extend a key that expires sooner than the overlap', function () {
        [$old] = target(['expires_at' => now()->addMinutes(10)]);

        manage('postJson', "/{$old->id}/rotate")->assertCreated();

        expect(fresh($old)->expires_at?->toIso8601ZuluString())->toBe('2026-10-19T12:10:00Z');
    });

    it('copies a publishable key\'s origins to the successor', function () {
        [$old] = target(['scopes' => [], 'allowed_origins' => ['https://app.example.com', 'http://localhost:3000']], KeyType::Publishable);

        $response = manage('postJson', "/{$old->id}/rotate")->assertCreated();

        expect($response->json('key'))->toMatch('/^hra_pk_live_[0-9a-f]{48}$/')
            ->and($response->json('allowed_origins'))->toBe(['https://app.example.com', 'http://localhost:3000'])
            ->and($response->json('scopes'))->toBe([]);
    });

    it('refuses to rotate a revoked or expired key', function (array $state) {
        [$old] = target($state);
        $before = ApiKey::withoutGlobalScopes()->count();

        $response = manage('postJson', "/{$old->id}/rotate");

        $response->assertStatus(409);
        expect($response->json('code'))->toBe('invalid_state')
            ->and(ApiKey::withoutGlobalScopes()->count())->toBe($before);
    })->with([
        'revoked' => [['revoked_at' => '2026-10-19 11:00:00']],
        'expired' => [['expires_at' => '2026-10-19 11:59:59']],
    ]);

    it('applies the same scope and mode limits as create, and changes nothing when refused', function (array $callerState, KeyMode $callerMode, string $code) {
        [, $caller] = issueKey($this->account, $callerState, mode: $callerMode);
        [$old] = target(['scopes' => ['conversations:write']]);
        $before = ApiKey::withoutGlobalScopes()->count();

        $response = manage('postJson', "/{$old->id}/rotate", [], $caller);

        $response->assertStatus(403);
        expect($response->json('code'))->toBe($code)
            ->and(ApiKey::withoutGlobalScopes()->count())->toBe($before)
            ->and(fresh($old)->expires_at)->toBeNull();
    })->with([
        'a scope the caller lacks' => [['scopes' => ['api_keys:manage', 'conversations:read']], KeyMode::Live, 'insufficient_scope'],
        'the other mode' => [['scopes' => ['*']], KeyMode::Test, 'forbidden'],
    ]);
});

describe('isolation and scope', function () {
    it('answers 404 for another account\'s key and leaves it untouched', function (string $action) {
        $other = Account::factory()->create();
        [$foreign, $foreignRaw] = issueKey($other, ['scopes' => ['*']]);

        $response = manage('postJson', "/{$foreign->id}/{$action}");

        $response->assertStatus(404);
        expect($response->json('code'))->toBe('not_found')
            ->and(fresh($foreign)->revoked_at)->toBeNull()
            ->and(fresh($foreign)->expires_at)->toBeNull()
            ->and(ApiKey::withoutGlobalScopes()->where('rotated_from_id', $foreign->id)->exists())->toBeFalse();
        authenticatesWith($foreignRaw)->assertOk();
    })->with(['revoke', 'rotate']);

    it('answers 404 for an id that does not exist', function (string $action) {
        manage('postJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ/'.$action)->assertStatus(404);
    })->with(['revoke', 'rotate']);

    it('requires the api_keys:manage scope', function (string $action) {
        [, $narrow] = issueKey($this->account, ['scopes' => ['conversations:read']]);
        [$key] = target();

        $response = manage('postJson', "/{$key->id}/{$action}", [], $narrow);

        $response->assertStatus(403);
        expect($response->json('code'))->toBe('insufficient_scope');
    })->with(['revoke', 'rotate']);

    it('requires authentication', function (string $action) {
        [$key] = target();

        $this->postJson("/v1/api-keys/{$key->id}/{$action}")->assertStatus(401);
    })->with(['revoke', 'rotate']);
});
