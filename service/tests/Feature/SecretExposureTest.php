<?php

use App\Models\Account;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/**
 * A credential must never appear in a response, a log line or a stored column other than its
 * hash. Each slice that stores or returns a credential adds its case here.
 */
function captureLogs(): ArrayObject
{
    $logged = new ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($logged) {
        $logged->append($event->message.' '.json_encode($event->context));
    });

    return $logged;
}

it('keeps an API key out of the logs while it is created, authenticated with and serialized', function () {
    $logged = captureLogs();

    Artisan::call('hra:bootstrap-account', ['name' => 'Acme']);
    preg_match('/hra_sk_live_[0-9a-f]{96}/', Artisan::output(), $match);
    $raw = $match[0];

    $this->getJson('/v1/health', ['Authorization' => "Bearer {$raw}"])->assertOk();
    [$key, $other] = issueKey(Account::factory()->create());
    $key->toJson();

    $everything = implode("\n", $logged->getArrayCopy());

    expect($raw)->toStartWith('hra_sk_live_')
        ->and($everything)->not->toContain($raw)->not->toContain($other);
});

it('never echoes or logs a key that fails authentication', function () {
    $logged = captureLogs();
    Route::middleware('auth.secret')->get('/v1/_probe', fn () => ['ok' => true]);
    $wrong = 'hra_sk_live_'.str_repeat('f', 96);
    [, $expired] = issueKey(Account::factory()->create(), ['expires_at' => now()->subMinute()]);

    $responses = [
        $this->getJson('/v1/_probe', ['Authorization' => "Bearer {$wrong}"]),
        $this->getJson('/v1/_probe', ['Authorization' => "Bearer {$expired}"]),
        $this->getJson("/v1/_probe?api_key={$wrong}"),
    ];

    foreach ($responses as $response) {
        expect($response->getContent())->not->toContain($wrong)->not->toContain($expired);
    }
    expect(implode("\n", $logged->getArrayCopy()))->not->toContain($wrong)->not->toContain($expired);
});

it('keeps keys issued through the management API out of the logs and out of every later read', function () {
    $logged = captureLogs();
    [, $caller] = issueKey(Account::factory()->create(), ['scopes' => ['*']]);
    $headers = ['Authorization' => "Bearer {$caller}"];

    $created = $this->postJson('/v1/api-keys', ['type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read']], $headers)->assertCreated();
    $rotated = $this->postJson("/v1/api-keys/{$created->json('id')}/rotate", [], $headers)->assertCreated();
    $revoked = $this->postJson("/v1/api-keys/{$created->json('id')}/revoke", [], $headers)->assertOk();
    $listed = $this->getJson('/v1/api-keys', $headers)->assertOk();
    $refused = $this->postJson('/v1/api-keys', ['type' => 'secret', 'mode' => 'live', 'scopes' => ['bogus']], $headers);

    $keys = [$created->json('key'), $rotated->json('key')];
    $hashes = [hash('sha256', $keys[0]), hash('sha256', $keys[1])];

    foreach ([$revoked, $listed, $refused] as $response) {
        foreach ([...$keys, ...$hashes] as $secret) {
            expect($response->getContent())->not->toContain($secret);
        }
    }
    foreach ([...$keys, ...$hashes, $caller] as $secret) {
        expect(implode("\n", $logged->getArrayCopy()))->not->toContain($secret);
    }
});
