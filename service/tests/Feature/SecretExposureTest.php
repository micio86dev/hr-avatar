<?php

use App\Models\Account;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

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
