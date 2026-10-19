<?php

use App\Http\Middleware\RejectKeyInQuery;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(RejectKeyInQuery::class)->get('/v1/_probe', fn () => ['ok' => true]);
});

it('rejects a key passed in the query string', function (string $query) {
    $response = $this->getJson("/v1/_probe?{$query}");

    $response->assertStatus(400);
    expect($response->json('code'))->toBe('api_key_in_query')
        ->and($response->getContent())->not->toContain('hra_sk_live_')->not->toContain('hra_pk_test_');
})->with([
    'api_key parameter' => ['api_key=anything'],
    'key parameter' => ['key=anything'],
    'secret key value under another name' => ['token=hra_sk_live_'.'ab12cd34'],
    'publishable key value under another name' => ['t=hra_pk_test_'.'ab12cd34'],
    'nested value' => ['filter[a][]=hra_sk_test_ab12cd34'],
    'secret key alongside a clean parameter' => ['limit=10&x=hra_sk_live_ab12cd34'],
]);

it('lets a clean query through', function (string $query) {
    $this->getJson("/v1/_probe?{$query}")->assertOk()->assertJson(['ok' => true]);
})->with([
    'no query' => [''],
    'ordinary parameters' => ['limit=10&cursor=abc'],
    'a parameter that merely starts with key' => ['key_prefix=ab12cd34'],
    'a value that only resembles a key' => ['name=hra_skeleton'],
    'a key in the middle of a value' => ['q=see hra_sk_live_ab12cd34'],
]);

it('runs before authentication, so a key in the query is named before credentials are judged', function () {
    // Inside the `api` group, as every real route is: the group adds middleware that Laravel sorts by priority.
    Route::middleware(['api', RejectKeyInQuery::class, 'auth.secret', 'throttle:secret', 'scope:conversations:read'])
        ->get('/v1/_chain', fn () => ['ok' => true]);

    expect($this->getJson('/v1/_chain?api_key=x')->json('code'))->toBe('api_key_in_query')
        ->and($this->getJson('/v1/_chain')->json('code'))->toBe('unauthenticated');
});
