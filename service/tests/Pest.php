<?php

use App\Accounts\CurrentAccount;
use App\Keys\KeyGenerator;
use App\Keys\KeyMode;
use App\Keys\KeyType;
use App\Models\Account;
use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * Feature and Unit tests boot the application against the PostgreSQL test database
 * (json, jsonb and CHECK constraints need a real engine). Every outbound HTTP call
 * must be faked: a stray request is a test failure, never a network call.
 * Architecture tests boot the application but need no database.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature', 'Unit');

pest()->extend(TestCase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Arch');

/**
 * Runs a callback with an account bound, as the authentication middleware would for a request.
 */
function inAccount(Account $account, Closure $callback): mixed
{
    return CurrentAccount::runAs($account->id, $callback);
}

/**
 * Creates a key for an account and returns it with its raw value, which only exists here.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: ApiKey, 1: string}
 */
function issueKey(
    Account $account,
    array $attributes = [],
    KeyType $type = KeyType::Secret,
    KeyMode $mode = KeyMode::Live,
): array {
    $raw = KeyGenerator::generate($type, $mode);

    return [inAccount($account, fn () => ApiKey::factory()->withRawKey($raw)->create($attributes)), $raw];
}

/**
 * Records everything the application logs from now on, message, context and (for a logged
 * exception) its message and stack trace as text, so a test can assert a secret is nowhere in it.
 *
 * @return ArrayObject<int, string>
 */
function captureLogs(): ArrayObject
{
    $logged = new ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($logged) {
        $exception = $event->context['exception'] ?? null;
        $context = array_diff_key($event->context, ['exception' => true]);

        $logged->append($event->message.' '.json_encode($context).($exception instanceof Throwable ? ' '.$exception->getMessage().' '.$exception->getTraceAsString() : ''));
    });

    return $logged;
}
