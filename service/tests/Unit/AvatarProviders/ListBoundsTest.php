<?php

use App\AvatarProviders\ListBounds;
use App\AvatarProviders\LiveAvatar\LiveAvatarApi;
use App\AvatarProviders\LiveAvatar\LiveAvatarCatalogue;
use App\AvatarProviders\Tavus\TavusCatalogue;
use App\Credentials\ProviderAuth;
use App\Voices\CartesiaVoices;
use App\Voices\ElevenLabsVoices;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
 * Review rv18 R3-002 and rv19 R3-001: a list that never ends, or that is answered slowly page after page, is cut at
 * 20 pages or 30 seconds, and says so in the log; a list that ends is read whole and says nothing.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    $this->auth = new ProviderAuth('key-123456789', ProviderAuth::PLATFORM);
});

/**
 * Each case: how to read the list, the answer to one page (that always says another follows unless asked not to),
 * and the list's name in the warning.
 *
 * @return array<string, array{0: Closure(ProviderAuth): mixed, 1: Closure(bool, int): mixed, 2: string}>
 */
function pagedLists(): array
{
    $rows = fn (int $count, Closure $row): array => array_map($row, range(1, $count));

    return [
        'liveavatar' => [
            fn (ProviderAuth $auth) => (new LiveAvatarCatalogue(new LiveAvatarApi))->list('voice', $auth),
            fn (bool $more, int $page) => Http::response(['data' => ['results' => [['id' => "a{$page}", 'name' => "A {$page}"]], 'next' => $more ? 'https://next' : null]]),
            'liveavatar:voices',
        ],
        'tavus' => [
            fn (ProviderAuth $auth) => (new TavusCatalogue)->list('pal', $auth),
            fn (bool $more, int $page) => Http::response(['data' => $rows($more ? 100 : 3, fn (int $n) => ['pal_id' => "p{$page}-{$n}", 'pal_name' => "P {$n}"])]),
            'tavus:pals',
        ],
        'cartesia' => [
            fn (ProviderAuth $auth) => (new CartesiaVoices)->voices($auth),
            fn (bool $more, int $page) => Http::response(['data' => [['id' => "v{$page}", 'name' => "V {$page}"]], 'has_more' => $more, 'next_page' => $more ? "p{$page}" : null]),
            'cartesia:voices',
        ],
        'elevenlabs' => [
            fn (ProviderAuth $auth) => (new ElevenLabsVoices)->voices($auth),
            fn (bool $more, int $page) => Http::response(['voices' => [['voice_id' => "v{$page}", 'name' => "V {$page}"]], 'has_more' => $more, 'next_page_token' => $more ? "t{$page}" : null]),
            'elevenlabs:voices',
        ],
    ];
}

it('reads a list that ends, whole and without a word in the log', function (Closure $read, Closure $answer) {
    $logs = captureLogs();
    $page = 0;
    Http::fake(function (Request $request) use ($answer, &$page) {
        $page++;

        return $answer($page < 3, $page);
    });

    $read($this->auth);

    expect($page)->toBe(3)->and($logs->getArrayCopy())->toBe([]);
})->with(pagedLists());

it('stops a list that never ends at 20 pages, and says which list it cut', function (Closure $read, Closure $answer, string $name) {
    $logs = captureLogs();
    $page = 0;
    Http::fake(function (Request $request) use ($answer, &$page) {
        $page++;

        return $answer(true, $page);
    });

    $read($this->auth);

    $everything = implode("\n", $logs->getArrayCopy());
    expect($page)->toBe(20)->and($everything)->toContain('A provider list was cut short')->and($everything)->toContain($name);
})->with(pagedLists());

it('stops a list that is answered slowly at the deadline, whatever the page count', function (Closure $read, Closure $answer, string $name) {
    $logs = captureLogs();
    $page = 0;
    Http::fake(function (Request $request) use ($answer, &$page) {
        // The page took 20 seconds: the second still starts (20 < 30), the third does not (40 >= 30).
        Carbon::setTestNow(now()->addSeconds(20));

        $page++;

        return $answer(true, $page);
    });

    $read($this->auth);

    expect($page)->toBe(2)->and(implode("\n", $logs->getArrayCopy()))->toContain('A provider list was cut short')->toContain($name);
})->with(pagedLists());

it('has a deadline of 30 seconds, counted from when the listing started', function () {
    $bounds = new ListBounds('x');

    Carbon::setTestNow(now()->addSeconds(29));
    expect($bounds->expired())->toBeFalse();

    Carbon::setTestNow(now()->addSecond());
    expect($bounds->expired())->toBeTrue();
});

it('gives the two lists of one resource a single deadline', function () {
    $logs = captureLogs();
    $page = 0;
    Http::fake(function (Request $request) use (&$page) {
        // Every page takes 20 seconds: the public list reads two pages, and the account's own list then starts expired.
        Carbon::setTestNow(now()->addSeconds(20));
        $page++;

        return Http::response(['data' => ['results' => [['id' => "a{$page}", 'name' => "A {$page}", 'status' => 'ACTIVE']], 'next' => 'https://next']]);
    });

    (new LiveAvatarCatalogue(new LiveAvatarApi))->list('avatar', new ProviderAuth('key-123456789', 'acc_1'));

    expect($page)->toBe(2)->and(implode("\n", $logs->getArrayCopy()))->toContain('liveavatar:avatars');
});
