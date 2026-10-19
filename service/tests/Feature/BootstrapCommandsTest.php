<?php

use App\Keys\KeyGenerator;
use App\Keys\KeyResolver;
use App\Keys\KeyType;
use App\Models\Account;
use App\Models\ApiKey;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function runCommand(string $command, array $parameters = []): array
{
    $exit = Artisan::call($command, $parameters);

    return [$exit, Artisan::output()];
}

it('creates an account and a wildcard secret key and prints the key once', function () {
    [$exit, $output] = runCommand('hra:bootstrap-account', ['name' => 'Acme']);

    expect($exit)->toBe(0)
        ->and(preg_match_all('/^hra_sk_live_[0-9a-f]{96}$/m', $output, $keys))->toBe(1);

    $account = Account::where('name', 'Acme')->sole();
    $row = DB::table('api_keys')->sole();

    expect($row->account_id)->toBe($account->id)
        ->and($row->type)->toBe('secret')
        ->and($row->mode)->toBe('live')
        ->and(json_decode($row->scopes))->toBe(['*'])
        ->and($row->key_hash)->toBe(KeyGenerator::hash($keys[0][0]));
    expect($output)->toContain($account->id);
});

it('prints a key that authenticates', function () {
    [, $output] = runCommand('hra:bootstrap-account', ['name' => 'Acme']);
    preg_match('/^hra_sk_live_[0-9a-f]{96}$/m', $output, $key);

    expect(app(KeyResolver::class)->resolve($key[0], KeyType::Secret)->scopes)->toBe(['*']);
});

it('never stores or logs the key it prints', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.json_encode($event->context);
    });

    [, $output] = runCommand('hra:bootstrap-account', ['name' => 'Acme']);
    preg_match('/hra_sk_live_[0-9a-f]{96}/', $output, $key);

    $stored = json_encode(DB::table('api_keys')->get()->all()).json_encode(DB::table('accounts')->get()->all());

    expect($key)->not->toBeEmpty()
        ->and($stored)->not->toContain($key[0])
        ->and(implode("\n", $logged))->not->toContain($key[0]);
});

it('issues a test-mode key with --mode=test', function () {
    [$exit, $output] = runCommand('hra:bootstrap-account', ['name' => 'Acme', '--mode' => 'test']);

    expect($exit)->toBe(0)->and($output)->toMatch('/^hra_sk_test_[0-9a-f]{96}$/m');
    expect(DB::table('api_keys')->value('mode'))->toBe('test');
});

it('refuses an unknown mode before creating anything', function () {
    [$exit, $output] = runCommand('hra:bootstrap-account', ['name' => 'Acme', '--mode' => 'prod']);

    expect($exit)->not->toBe(0)
        ->and($output)->not->toContain('hra_sk_')
        ->and(Account::count())->toBe(0)
        ->and(DB::table('api_keys')->count())->toBe(0);
});

it('refuses a blank account name', function (string $name) {
    [$exit, $output] = runCommand('hra:bootstrap-account', ['name' => $name]);

    expect($exit)->not->toBe(0)
        ->and($output)->not->toContain('hra_sk_')
        ->and(Account::count())->toBe(0);
})->with(['empty' => [''], 'spaces' => ['   ']]);

it('issues an additional key for an existing account', function () {
    $account = Account::factory()->create();

    [$exit, $output] = runCommand('hra:issue-key', ['account_id' => $account->id]);

    expect($exit)->toBe(0)->and($output)->toMatch('/^hra_sk_live_[0-9a-f]{96}$/m');
    $row = DB::table('api_keys')->sole();
    expect($row->account_id)->toBe($account->id)->and(json_decode($row->scopes))->toBe(['*']);
});

it('issues a key with the requested scopes and mode', function () {
    $account = Account::factory()->create();

    [$exit, $output] = runCommand('hra:issue-key', [
        'account_id' => $account->id,
        '--mode' => 'test',
        '--scopes' => 'conversations:read,conversations:write',
    ]);

    expect($exit)->toBe(0)->and($output)->toMatch('/^hra_sk_test_[0-9a-f]{96}$/m');
    $row = DB::table('api_keys')->sole();
    expect(json_decode($row->scopes))->toBe(['conversations:read', 'conversations:write'])->and($row->mode)->toBe('test');
});

it('refuses an unknown account and creates no key', function () {
    [$exit, $output] = runCommand('hra:issue-key', ['account_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);

    expect($exit)->not->toBe(0)
        ->and($output)->not->toContain('hra_sk_')
        ->and(ApiKey::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses an unknown scope and creates no key', function (string $scopes) {
    $account = Account::factory()->create();

    [$exit, $output] = runCommand('hra:issue-key', ['account_id' => $account->id, '--scopes' => $scopes]);

    expect($exit)->not->toBe(0)
        ->and($output)->not->toContain('hra_sk_')
        ->and(DB::table('api_keys')->count())->toBe(0);
})->with(['bogus:scope', 'conversations:read,bogus:scope', '']);

it('refuses an unknown mode when issuing', function () {
    $account = Account::factory()->create();

    [$exit] = runCommand('hra:issue-key', ['account_id' => $account->id, '--mode' => 'prod']);

    expect($exit)->not->toBe(0)->and(DB::table('api_keys')->count())->toBe(0);
});
