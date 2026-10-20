<?php

use App\AvatarProviders\CatalogueEntry;
use App\AvatarProviders\LiveAvatar\LiveAvatarCatalogue;
use App\AvatarProviders\LiveAvatar\LiveAvatarProvider;
use App\AvatarProviders\ProviderException;
use App\Credentials\ProviderAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->auth = new ProviderAuth('SUPER_SECRET_LA_KEY_67890', 'acc_01');
});

/**
 * @return list<array<string, mixed>>
 */
function liveAvatarCatalogueRows(string $resource): array
{
    return array_map(fn (CatalogueEntry $entry) => $entry->toArray(), app(LiveAvatarCatalogue::class)->list($resource, test()->auth));
}

function liveAvatarEntry(string $id, string $name, array $overrides = []): array
{
    return [...(new CatalogueEntry($id, $name))->toArray(), ...$overrides];
}

/**
 * @param  list<array<string, mixed>>  $results
 * @return array<string, mixed>
 */
function liveAvatarPage(array $results, ?string $next = null): array
{
    return ['code' => 1000, 'data' => ['count' => count($results), 'next' => $next, 'previous' => null, 'results' => $results], 'message' => 'ok'];
}

describe('voices', function () {
    it('reports the language the provider states, verbatim, and no preview url', function () {
        Http::fake(['api.liveavatar.com/v1/voices*' => Http::sequence()
            // The accent bug this guards: an Italian-sounding name on an English-tagged voice.
            ->push(liveAvatarPage([['id' => 'hv1', 'name' => 'Alessandra - IA', 'language' => 'en'], ['id' => 'hv2', 'name' => 'Marco', 'language' => 'it']]))
            ->push(liveAvatarPage([]))]);

        expect(liveAvatarCatalogueRows('voice'))->toBe([
            liveAvatarEntry('hv1', 'Alessandra - IA', ['language' => 'en', 'preview_audio_via_api' => true]),
            liveAvatarEntry('hv2', 'Marco', ['language' => 'it', 'preview_audio_via_api' => true]),
        ]);
    });

    it('leaves the language null when the provider does not say, whatever the name suggests', function () {
        Http::fake(['api.liveavatar.com/v1/voices*' => Http::response(liveAvatarPage([['id' => 'hv1', 'name' => 'Giulia Italiana']]))]);

        expect(liveAvatarCatalogueRows('voice')[0]['language'])->toBeNull();
    });

    it('includes the account\'s private voices and drops a duplicate', function () {
        Http::fake(['api.liveavatar.com/v1/voices*' => Http::sequence()
            ->push(liveAvatarPage([['id' => 'hv1', 'name' => 'Pub', 'language' => 'en']]))
            ->push(liveAvatarPage([['id' => 'hv9', 'name' => 'Mine', 'language' => 'it'], ['id' => 'hv1', 'name' => 'Pub', 'language' => 'en']]))]);

        expect(array_column(liveAvatarCatalogueRows('voice'), 'id'))->toBe(['hv1', 'hv9']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'voice_type=private'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'voice_type=public'));
    });

    it('never lists the private voices of the platform key: they hold what other accounts bound', function () {
        Http::fake(['api.liveavatar.com/v1/voices*' => Http::response(liveAvatarPage([['id' => 'hv1', 'name' => 'Pub', 'language' => 'en']]))]);

        $rows = array_map(fn (CatalogueEntry $entry) => $entry->id, app(LiveAvatarCatalogue::class)->list('voice', new ProviderAuth('platform-key-1234', ProviderAuth::PLATFORM)));

        expect($rows)->toBe(['hv1']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'voice_type=private'));
    });

    it('lists the private voices of an account\'s own key', function () {
        Http::fake(['api.liveavatar.com/v1/voices*' => Http::response(liveAvatarPage([['id' => 'hv1', 'name' => 'Pub', 'language' => 'en']]))]);

        app(LiveAvatarCatalogue::class)->list('voice', new ProviderAuth('own-account-key-12', 'acc_01'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'voice_type=private'));
    });

    it('still lists the public voices when the private list is refused', function () {
        Http::fake(['api.liveavatar.com/v1/voices*' => Http::sequence()
            ->push(liveAvatarPage([['id' => 'hv1', 'name' => 'Pub', 'language' => 'en']]))
            ->push(['message' => 'nope'], 403)]);

        expect(array_column(liveAvatarCatalogueRows('voice'), 'id'))->toBe(['hv1']);
    });

    it('fails when the public list is refused', function () {
        Http::fake(['api.liveavatar.com/v1/voices*' => Http::response(['message' => 'SUPER_SECRET_LA_KEY_67890'], 500)]);

        expect(fn () => liveAvatarCatalogueRows('voice'))->toThrow(ProviderException::class, 'Provider request failed (status 500)');
    });
});

describe('avatars', function () {
    it('normalizes preview_image_url from preview_url', function () {
        Http::fake(['api.liveavatar.com/v1/avatars*' => Http::response(liveAvatarPage([['id' => 'ha1', 'name' => 'Studio Avatar', 'preview_url' => 'https://cdn.liveavatar.com/ha1.png']]))]);

        expect(liveAvatarCatalogueRows('avatar')[0])->toBe(liveAvatarEntry('ha1', 'Studio Avatar', ['preview_image_url' => 'https://cdn.liveavatar.com/ha1.png']));
    });

    it('merges the public catalogue with the account\'s own, drops expired and inactive ones and dedupes', function () {
        Http::fake([
            'api.liveavatar.com/v1/avatars/public*' => Http::response(liveAvatarPage([
                ['id' => 'p1', 'name' => 'Public One', 'status' => 'ACTIVE', 'is_expired' => false, 'preview_url' => 'https://cdn.example/p1.png'],
                ['id' => 'p2', 'name' => 'Expired', 'status' => 'ACTIVE', 'is_expired' => true],
                ['id' => 'p3', 'name' => 'Failed', 'status' => 'FAILED', 'is_expired' => false],
            ])),
            'api.liveavatar.com/v1/avatars*' => Http::response(liveAvatarPage([
                ['id' => 'u1', 'name' => 'Mine', 'status' => 'ACTIVE', 'is_expired' => false, 'preview_url' => 'https://cdn.example/u1.png'],
                ['id' => 'p1', 'name' => 'Public One', 'status' => 'ACTIVE', 'is_expired' => false],
            ])),
        ]);

        $rows = liveAvatarCatalogueRows('avatar');

        expect(array_column($rows, 'id'))->toBe(['p1', 'u1'])->and($rows[0]['preview_image_url'])->toBe('https://cdn.example/p1.png');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/avatars/public') && str_contains($request->url(), 'page_size=100'));
    });

    it('still lists the public avatars when the account\'s own list is refused', function () {
        Http::fake([
            'api.liveavatar.com/v1/avatars/public*' => Http::response(liveAvatarPage([['id' => 'p1', 'name' => 'Public One', 'status' => 'ACTIVE']])),
            'api.liveavatar.com/v1/avatars*' => Http::response(['message' => 'nope'], 403),
        ]);

        expect(array_column(liveAvatarCatalogueRows('avatar'), 'id'))->toBe(['p1']);
    });

    it('follows next until it is empty', function () {
        Http::fake([
            'api.liveavatar.com/v1/avatars/public*' => Http::sequence()
                ->push(liveAvatarPage([['id' => 'p1', 'name' => 'A']], 'https://api.liveavatar.com/v1/avatars/public?page=2'))
                ->push(liveAvatarPage([['id' => 'p2', 'name' => 'B']])),
            'api.liveavatar.com/v1/avatars*' => Http::response(liveAvatarPage([])),
        ]);

        expect(array_column(liveAvatarCatalogueRows('avatar'), 'id'))->toBe(['p1', 'p2']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'avatars/public') && str_contains($request->url(), 'page=2'));
    });

    it('stops at the page bound', function () {
        Http::fake([
            'api.liveavatar.com/v1/avatars/public*' => Http::response(liveAvatarPage([['id' => 'p1', 'name' => 'A']], 'https://api.liveavatar.com/v1/avatars/public?page=2')),
            'api.liveavatar.com/v1/avatars*' => Http::response(liveAvatarPage([])),
        ]);

        liveAvatarCatalogueRows('avatar');

        expect(Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), '/avatars/public'))->count())->toBe(20);
    });
});

describe('every request and failure', function () {
    it('authenticates with the key header and asks one hundred at a time', function () {
        Http::fake(['api.liveavatar.com/v1/*' => Http::response(liveAvatarPage([]))]);

        liveAvatarCatalogueRows('avatar');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->header('X-API-KEY') === ['SUPER_SECRET_LA_KEY_67890'] && str_contains($request->url(), 'page_size=100'));
    });

    it('raises a provider exception when the provider cannot be reached', function () {
        Http::fake(fn () => throw new ConnectionException('timed out with SUPER_SECRET_LA_KEY_67890'));

        expect(fn () => liveAvatarCatalogueRows('avatar'))->toThrow(ProviderException::class, 'Provider request failed (no response)');
    });

    it('treats an answer without a result list as empty', function () {
        Http::fake(['api.liveavatar.com/v1/*' => Http::response(['code' => 1000, 'data' => null])]);

        expect(liveAvatarCatalogueRows('avatar'))->toBe([]);
    });

    it('ignores rows that are not objects and entries with no id', function () {
        Http::fake(['api.liveavatar.com/v1/*' => Http::response(liveAvatarPage(['junk', ['name' => 'No id'], ['id' => 'ok', 'name' => 'Ok']]))]);

        expect(array_column(liveAvatarCatalogueRows('avatar'), 'id'))->toBe(['ok']);
    });

    it('refuses a resource it does not list', function (string $resource) {
        Http::fake();

        expect(fn () => app(LiveAvatarCatalogue::class)->list($resource, $this->auth))->toThrow(InvalidArgumentException::class);
        Http::assertNothingSent();
    })->with(['replica', 'pal', '']);

    it('is the adapter\'s catalogue', function () {
        Http::fake(['api.liveavatar.com/v1/*' => Http::response(liveAvatarPage([['id' => 'ha1', 'name' => 'A']]))]);

        expect(app(LiveAvatarProvider::class)->catalogue('avatar', $this->auth))->toHaveCount(1);
    });
});
