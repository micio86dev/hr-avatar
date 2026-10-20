<?php

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/*
 * The failed-authentication budget belongs to the caller's address. Behind a proxy that address is the proxy's
 * unless the proxy is trusted to say who called: untrusted, everyone shares one budget (one guesser locks all
 * out); trusted without cause, anyone can pick the address they are counted under.
 */
beforeEach(function () {
    config()->set('hr-avatar.rate_limits.failed_auth', 3);
    RateLimiter::clear('failed-auth:10.0.0.1');
});

/** A call with a wrong key, from the given peer, claiming to be forwarded for `$client`. */
function wrongKeyCall(string $peer, string $client): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $peer])
        ->getJson('/v1/api-keys', ['Authorization' => 'Bearer hra_sk_live_'.str_repeat('0', 96), 'X-Forwarded-For' => $client]);
}

it('ignores a forwarded address by default: callers behind a proxy that is not trusted share its budget', function () {
    foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $client) {
        wrongKeyCall('10.0.0.1', $client)->assertUnauthorized();
    }

    wrongKeyCall('10.0.0.1', '198.51.100.4')->assertStatus(429)->assertJsonPath('code', 'rate_limited');
});

it('counts each caller under the forwarded address when the proxy is trusted', function (string $trusted) {
    config()->set('trustedproxy.proxies', $trusted);

    foreach (range(1, 3) as $ignored) {
        wrongKeyCall('10.0.0.1', '198.51.100.1')->assertUnauthorized();
    }

    wrongKeyCall('10.0.0.1', '198.51.100.1')->assertStatus(429);
    wrongKeyCall('10.0.0.1', '198.51.100.2')->assertUnauthorized();
})->with(['all' => '*', 'the proxy by address' => '10.0.0.1', 'a list' => '192.0.2.9, 10.0.0.1']);

it('trusts only the proxies it names', function () {
    config()->set('trustedproxy.proxies', '10.0.0.1');

    foreach (range(1, 3) as $ignored) {
        wrongKeyCall('10.0.0.2', '198.51.100.'.$ignored)->assertUnauthorized();
    }

    // 10.0.0.2 is not a trusted proxy: what it forwards is ignored, so it is one address with three failures.
    wrongKeyCall('10.0.0.2', '198.51.100.9')->assertStatus(429);
});

it('reads no proxy from an empty setting', function () {
    config()->set('trustedproxy.proxies', null);

    foreach (range(1, 3) as $ignored) {
        wrongKeyCall('10.0.0.1', '198.51.100.'.$ignored)->assertUnauthorized();
    }

    wrongKeyCall('10.0.0.1', '198.51.100.9')->assertStatus(429);
});

it('reads the setting from HRA_TRUSTED_PROXIES', function () {
    expect(file_get_contents(base_path('config/trustedproxy.php')))->toContain("env('HRA_TRUSTED_PROXIES')")
        ->and(file_get_contents(base_path('.env.example')))->toContain('HRA_TRUSTED_PROXIES=');
});
