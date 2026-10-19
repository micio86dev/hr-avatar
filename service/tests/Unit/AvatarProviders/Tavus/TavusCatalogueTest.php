<?php

use App\AvatarProviders\CatalogueEntry;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\Tavus\TavusCatalogue;
use App\AvatarProviders\Tavus\TavusProvider;
use App\Credentials\ProviderAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->auth = new ProviderAuth('SUPER_SECRET_TAVUS_KEY_12345', ProviderAuth::PLATFORM);
});

/**
 * @return list<array<string, mixed>>
 */
function tavusCatalogueRows(string $resource): array
{
    return array_map(fn (CatalogueEntry $entry) => $entry->toArray(), (new TavusCatalogue)->list($resource, test()->auth));
}

function tavusEntry(string $id, string $name, array $overrides = []): array
{
    return [...(new CatalogueEntry($id, $name))->toArray(), ...$overrides];
}

describe('faces (the replicas)', function () {
    it('normalizes the thumbnail video into preview_video_url and skips faces that are not trained', function () {
        Http::fake([
            'tavusapi.com/v2/faces*' => Http::response([
                'data' => [
                    ['face_id' => 'r1', 'face_name' => 'Face One', 'status' => 'completed', 'thumbnail_video_url' => 'https://cdn.replica.tavus.io/r1/thumb.mp4', 'default_voice_id' => 'v1'],
                    ['face_id' => 'r2', 'face_name' => 'Still Training', 'status' => 'started'],
                    ['face_id' => 'r3', 'face_name' => 'Broken', 'status' => 'error'],
                ],
                'total_count' => 3,
            ]),
        ]);

        expect(tavusCatalogueRows('replica'))->toBe([tavusEntry('r1', 'Face One', ['preview_video_url' => 'https://cdn.replica.tavus.io/r1/thumb.mp4'])]);
    });

    it('reads a still thumbnail when the face has one, and never invents a language', function () {
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(['data' => [['face_id' => 'r1', 'face_name' => 'One', 'thumbnail_image_url' => 'https://cdn.example/r1.png']], 'total_count' => 1])]);

        $row = tavusCatalogueRows('replica')[0];

        expect($row['preview_image_url'])->toBe('https://cdn.example/r1.png')->and($row['language'])->toBeNull();
    });

    it('still reads the former replica field names', function () {
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(['data' => [['replica_id' => 'old', 'replica_name' => 'Old Name']], 'total_count' => 1])]);

        expect(tavusCatalogueRows('replica'))->toBe([tavusEntry('old', 'Old Name')]);
    });

    it('asks for the verbose list with the account key, one hundred at a time', function () {
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(['data' => [], 'total_count' => 0])]);

        tavusCatalogueRows('replica');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://tavusapi.com/v2/faces?')
            && str_contains($request->url(), 'verbose=true') && str_contains($request->url(), 'limit=100') && str_contains($request->url(), 'page=1')
            && $request->header('x-api-key') === ['SUPER_SECRET_TAVUS_KEY_12345']);
    });
});

describe('personas (the pals)', function () {
    it('lists only id and name: never the system prompt or the layers', function () {
        Http::fake(['tavusapi.com/v2/pals*' => Http::response([
            'data' => [[
                'pal_id' => 'p1', 'pal_name' => 'Interviewer', 'default_face_id' => 'f1',
                'system_prompt' => 'CONFIDENTIAL PROMPT', 'layers' => ['llm' => ['api_key' => 'SECRET_LAYER_KEY']],
            ]],
            'total_count' => 1,
        ])]);

        $rows = tavusCatalogueRows('pal');

        expect($rows)->toBe([tavusEntry('p1', 'Interviewer')])
            ->and(json_encode($rows))->not->toContain('CONFIDENTIAL')->not->toContain('SECRET_LAYER_KEY');
    });

    it('reads the persona field names too', function () {
        Http::fake(['tavusapi.com/v2/pals*' => Http::response(['data' => [['persona_id' => 'p2', 'persona_name' => 'Named']], 'total_count' => 1])]);

        expect(tavusCatalogueRows('pal'))->toBe([tavusEntry('p2', 'Named')]);
    });

    it('makes one list call, not a call per kind of persona', function () {
        Http::fake(['tavusapi.com/v2/pals*' => Http::response(['data' => [['pal_id' => 'p1', 'pal_name' => 'A']], 'total_count' => 1])]);

        tavusCatalogueRows('pal');

        Http::assertSentCount(1);
    });
});

describe('paging and shape', function () {
    it('pages past the provider default of ten until the total is reached', function () {
        $page1 = array_map(fn (int $i) => ['face_id' => "a{$i}", 'face_name' => "A{$i}", 'status' => 'completed'], range(1, 100));
        Http::fake(['tavusapi.com/v2/faces*' => Http::sequence()
            ->push(['data' => $page1, 'total_count' => 101])
            ->push(['data' => [['face_id' => 'b1', 'face_name' => 'B1', 'status' => 'completed']], 'total_count' => 101])]);

        expect(tavusCatalogueRows('replica'))->toHaveCount(101);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'page=2'));
    });

    it('stops at the page bound so a misbehaving provider cannot loop the worker', function () {
        $full = array_map(fn (int $i) => ['face_id' => "a{$i}", 'face_name' => "A{$i}", 'status' => 'completed'], range(1, 100));
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(['data' => $full, 'total_count' => 999999])]);

        tavusCatalogueRows('replica');

        Http::assertSentCount(20);
    });

    it('ignores rows that are not objects and entries with no id', function () {
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(['data' => ['junk', ['face_name' => 'No id'], ['face_id' => 'ok', 'face_name' => 'Ok']], 'total_count' => 3])]);

        expect(array_column(tavusCatalogueRows('replica'), 'id'))->toBe(['ok']);
    });

    it('is empty for an answer with no data', function () {
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(['data' => [], 'total_count' => 0])]);

        expect(tavusCatalogueRows('replica'))->toBe([]);
    });

    it('refuses a resource it does not list', function (string $resource) {
        Http::fake();

        expect(fn () => (new TavusCatalogue)->list($resource, $this->auth))->toThrow(InvalidArgumentException::class);
        Http::assertNothingSent();
    })->with(['voice', 'avatar', '']);
});

describe('failures', function () {
    it('raises a provider exception with the status only, never the key or the body', function (int $status) {
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(['message' => 'Unauthorized: key SUPER_SECRET_TAVUS_KEY_12345 is invalid'], $status)]);

        try {
            tavusCatalogueRows('replica');
            $this->fail('A refused list must raise.');
        } catch (ProviderException $failure) {
            expect($failure->getMessage())->toBe("Provider request failed (status {$status})")
                ->and($failure->getMessage())->not->toContain('SUPER_SECRET')
                ->and($failure->getPrevious())->toBeNull();
        }
    })->with([401, 403, 404, 429, 500, 503]);

    it('raises a provider exception when the provider cannot be reached', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for SUPER_SECRET_TAVUS_KEY_12345'));

        expect(fn () => tavusCatalogueRows('pal'))->toThrow(ProviderException::class, 'Provider request failed (no response)');
    });

    it('raises when the answer is not a list envelope', function () {
        Http::fake(['tavusapi.com/v2/faces*' => Http::response('not json at all', 200)]);

        expect(fn () => tavusCatalogueRows('replica'))->toThrow(ProviderException::class, 'Provider request failed (no response)');
    });
});

it('is the adapter\'s catalogue', function () {
    Http::fake(['tavusapi.com/v2/pals*' => Http::response(['data' => [['pal_id' => 'p1', 'pal_name' => 'A']], 'total_count' => 1])]);

    $entries = (new TavusProvider)->catalogue('pal', $this->auth);

    expect($entries)->toHaveCount(1)->and($entries[0])->toBeInstanceOf(CatalogueEntry::class);
});
