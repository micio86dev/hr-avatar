<?php

use App\Models\Account;
use App\Models\ProviderCredential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Cache::flush();
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['avatar_configs:read']]);
    [, $this->noScope] = issueKey($this->account, ['scopes' => ['conversations:read']]);
    config()->set('hr-avatar.platform_keys.tavus', null);
    config()->set('hr-avatar.platform_keys.heygen', null);
});

function catalogueGet(string $uri, ?string $raw = null): TestResponse
{
    return test()->getJson($uri, ['Authorization' => 'Bearer '.($raw ?? test()->raw)]);
}

function catalogueKey(Account $account, string $provider, string $key): void
{
    inAccount($account, fn () => ProviderCredential::create(['provider' => $provider, 'api_key' => $key]));
}

/**
 * @return array<string, mixed>
 */
function catalogueFaces(string $id = 'r1', string $name = 'Face One'): array
{
    return ['data' => [['face_id' => $id, 'face_name' => $name, 'status' => 'completed', 'thumbnail_video_url' => 'https://cdn.example/r.mp4']], 'total_count' => 1];
}

const CATALOGUE_ENTRY_MEMBERS = ['id', 'name', 'language', 'preview_audio_url', 'preview_audio_via_api', 'preview_image_url', 'preview_video_url'];

describe('GET /v1/catalogue', function () {
    it('lists the voice vendors next to the providers, with the one resource they list', function () {
        $providers = array_column(catalogueGet('/v1/catalogue')->json('data'), null, 'provider');

        expect(array_keys($providers))->toBe(['mock', 'heygen', 'tavus', 'cartesia', 'elevenlabs'])
            ->and($providers['cartesia'])->toBe(['provider' => 'cartesia', 'fields' => [], 'persona_fields' => [], 'resources' => ['voice'], 'voice_engines' => []])
            ->and($providers['elevenlabs']['resources'])->toBe(['voice']);
    });

    it('describes every provider without calling any', function () {
        Http::fake();

        $response = catalogueGet('/v1/catalogue')->assertOk();

        $providers = array_column($response->json('data'), null, 'provider');
        expect(array_keys($response->json()))->toBe(['data'])
            ->and(array_keys($providers))->toBe(['mock', 'heygen', 'tavus', 'cartesia', 'elevenlabs'])
            ->and(array_keys($providers['tavus']))->toBe(['provider', 'fields', 'persona_fields', 'resources', 'voice_engines'])
            ->and(array_column($providers['tavus']['fields'], 'key'))->toBe(['replica_id', 'persona_id', 'participant_left_timeout', 'tts_model_name'])
            ->and($providers['tavus']['resources'])->toBe(['replica', 'pal'])
            ->and($providers['heygen']['resources'])->toBe(['avatar', 'voice'])
            ->and($providers['heygen']['fields'][0])->toBe(['key' => 'avatarId', 'type' => 'text', 'required' => true, 'catalogue_resource' => 'avatar'])
            ->and($providers['mock'])->toBe(['provider' => 'mock', 'fields' => [], 'persona_fields' => [], 'resources' => [], 'voice_engines' => ['provider']]);
        Http::assertNothingSent();
    });

    it('publishes the dependent choices of a setting so a client can narrow them', function () {
        $fields = array_column(catalogueGet('/v1/catalogue')->json('data.2.fields'), null, 'key');

        expect($fields['tts_model_name']['options_by_voice_engine']['cartesia'])->toContain('sonic-3')
            ->and($fields['tts_model_name']['voice_engines'])->toBe(['cartesia', 'elevenlabs'])
            ->and($fields['participant_left_timeout'])->toBe(['key' => 'participant_left_timeout', 'type' => 'integer', 'required' => false, 'min' => 0, 'max' => 3600]);
    });

    it('needs the avatar_configs:read scope and a key', function () {
        $response = catalogueGet('/v1/catalogue', $this->noScope)->assertStatus(403);

        expect($response->json('code'))->toBe('insufficient_scope');
        $this->getJson('/v1/catalogue')->assertStatus(401);
    });
});

describe('GET /v1/catalogue/{provider}/{resource}', function () {
    it('lists the inventory as normalized entries and nothing else', function () {
        catalogueKey($this->account, 'tavus', 'tv-key-123456');
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(catalogueFaces())]);

        $response = catalogueGet('/v1/catalogue/tavus/replica')->assertOk();

        expect(array_keys($response->json()))->toBe(['data'])
            ->and($response->json('data'))->toBe([[
                'id' => 'r1', 'name' => 'Face One', 'language' => null, 'preview_audio_url' => null,
                'preview_audio_via_api' => false, 'preview_image_url' => null, 'preview_video_url' => 'https://cdn.example/r.mp4',
            ]])
            ->and(array_keys($response->json('data.0')))->toBe(CATALOGUE_ENTRY_MEMBERS)
            ->and($response->getContent())->not->toContain('face_id')->not->toContain('default_voice');
    });

    it('uses the account key, else the platform key', function (bool $own, string $sent) {
        config()->set('hr-avatar.platform_keys.tavus', 'platform-key-9');
        if ($own) {
            catalogueKey($this->account, 'tavus', 'own-key-123456');
        }
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(catalogueFaces())]);

        catalogueGet('/v1/catalogue/tavus/replica')->assertOk();

        Http::assertSent(fn (Request $request) => $request->header('x-api-key') === [$sent]);
    })->with([
        'account key' => [true, 'own-key-123456'],
        'platform key' => [false, 'platform-key-9'],
    ]);

    it('answers 404 for a pair that does not exist, before it looks for a credential', function (string $path) {
        Http::fake();

        $response = catalogueGet($path)->assertNotFound();

        expect($response->json('code'))->toBe('not_found');
        Http::assertNothingSent();
    })->with([
        'resource of another provider' => ['/v1/catalogue/cartesia/replica'],
        'unknown provider' => ['/v1/catalogue/nope/replica'],
        'unknown resource' => ['/v1/catalogue/tavus/voice'],
        'mock lists nothing' => ['/v1/catalogue/mock/avatar'],
        'swapped' => ['/v1/catalogue/replica/tavus'],
    ]);

    it('answers 422 provider_credential_missing when neither the account nor the platform has a key', function () {
        Http::fake();

        $response = catalogueGet('/v1/catalogue/tavus/replica')->assertStatus(422);

        expect($response->json('code'))->toBe('provider_credential_missing');
        Http::assertNothingSent();
    });

    it('answers 502 provider_error without the key or the provider body when the provider fails', function (int $status) {
        catalogueKey($this->account, 'tavus', 'SUPER_SECRET_TAVUS_KEY_12345');
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(['message' => 'bad key SUPER_SECRET_TAVUS_KEY_12345', 'detail' => 'PROVIDER-BODY'], $status)]);
        $logs = captureLogs();

        $response = catalogueGet('/v1/catalogue/tavus/replica')->assertStatus(502);

        expect($response->json('code'))->toBe('provider_error')
            ->and($response->getContent())->not->toContain('SUPER_SECRET')->not->toContain('PROVIDER-BODY')
            ->and(implode("\n", $logs->getArrayCopy()))->not->toContain('SUPER_SECRET')->not->toContain('PROVIDER-BODY');
    })->with([401, 429, 500, 503]);

    it('answers 502 when the provider cannot be reached', function () {
        catalogueKey($this->account, 'tavus', 'tv-key-123456');
        Http::fake(fn () => throw new ConnectionException('timed out'));

        catalogueGet('/v1/catalogue/tavus/replica')->assertStatus(502)->assertJsonPath('code', 'provider_error');
    });

    it('does not cache a failure: the next call asks again', function () {
        catalogueKey($this->account, 'tavus', 'tv-key-123456');
        Http::fake(['tavusapi.com/v2/faces*' => Http::sequence()->push([], 500)->push(catalogueFaces())]);

        catalogueGet('/v1/catalogue/tavus/replica')->assertStatus(502);
        catalogueGet('/v1/catalogue/tavus/replica')->assertOk();

        Http::assertSentCount(2);
    });

    it('serves a repeat within five minutes from the cache and a stale one from the provider', function () {
        catalogueKey($this->account, 'tavus', 'tv-key-123456');
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(catalogueFaces())]);

        catalogueGet('/v1/catalogue/tavus/replica')->assertOk();
        $this->travel(299)->seconds();
        catalogueGet('/v1/catalogue/tavus/replica')->assertOk();
        Http::assertSentCount(1);

        $this->travel(2)->seconds();
        catalogueGet('/v1/catalogue/tavus/replica')->assertOk();
        Http::assertSentCount(2);
    });

    it('bypasses the cache with fresh=1 and refreshes it', function () {
        catalogueKey($this->account, 'tavus', 'tv-key-123456');
        Http::fake(['tavusapi.com/v2/faces*' => Http::sequence()->push(catalogueFaces('r1', 'Old'))->push(catalogueFaces('r2', 'New'))]);

        catalogueGet('/v1/catalogue/tavus/replica')->assertOk();
        $fresh = catalogueGet('/v1/catalogue/tavus/replica?fresh=1')->assertOk();
        $after = catalogueGet('/v1/catalogue/tavus/replica')->assertOk();

        expect($fresh->json('data.0.name'))->toBe('New')->and($after->json('data.0.name'))->toBe('New');
        Http::assertSentCount(2);
    });

    it('keeps the cache per provider and per resource', function () {
        catalogueKey($this->account, 'tavus', 'tv-key-123456');
        Http::fake(['tavusapi.com/v2/*' => Http::response(catalogueFaces())]);

        catalogueGet('/v1/catalogue/tavus/replica')->assertOk();
        catalogueGet('/v1/catalogue/tavus/pal')->assertOk();

        Http::assertSentCount(2);
    });

    it('never serves one account\'s cached inventory to another account with another key', function () {
        $other = Account::factory()->create();
        [, $otherRaw] = issueKey($other, ['scopes' => ['avatar_configs:read']]);
        catalogueKey($this->account, 'tavus', 'key-of-account-a-111');
        catalogueKey($other, 'tavus', 'key-of-account-b-222');
        Http::fake(['tavusapi.com/v2/faces*' => Http::sequence()->push(catalogueFaces('a1', 'Account A face'))->push(catalogueFaces('b1', 'Account B face'))]);

        $a = catalogueGet('/v1/catalogue/tavus/replica');
        $b = catalogueGet('/v1/catalogue/tavus/replica', $otherRaw);

        expect($a->json('data.0.id'))->toBe('a1')->and($b->json('data.0.id'))->toBe('b1');
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->header('x-api-key') === ['key-of-account-b-222']);
    });

    it('lets two accounts on the platform key share the platform inventory', function () {
        config()->set('hr-avatar.platform_keys.tavus', 'platform-key-9');
        $other = Account::factory()->create();
        [, $otherRaw] = issueKey($other, ['scopes' => ['avatar_configs:read']]);
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(catalogueFaces())]);

        catalogueGet('/v1/catalogue/tavus/replica')->assertOk();
        catalogueGet('/v1/catalogue/tavus/replica', $otherRaw)->assertOk();

        Http::assertSentCount(1);
    });

    it('does not cache under a key that has since been replaced', function () {
        catalogueKey($this->account, 'tavus', 'old-key-1234567');
        Http::fake(['tavusapi.com/v2/faces*' => Http::response(catalogueFaces())]);
        catalogueGet('/v1/catalogue/tavus/replica')->assertOk();

        inAccount($this->account, fn () => ProviderCredential::query()->where('provider', 'tavus')->first()?->update(['api_key' => 'new-key-7654321']));
        catalogueGet('/v1/catalogue/tavus/replica')->assertOk();

        Http::assertSentCount(2);
    });

    it('rejects a fresh value that is not a boolean', function () {
        catalogueKey($this->account, 'tavus', 'tv-key-123456');

        catalogueGet('/v1/catalogue/tavus/replica?fresh=maybe')->assertStatus(422);
    });

    it('lists a voice vendor\'s voices with the account\'s key', function (string $vendor, string $url, array $answer, string $header) {
        catalogueKey($this->account, $vendor, 'vendor-key-123456');
        Http::fake([$url => Http::response($answer)]);

        $response = catalogueGet("/v1/catalogue/{$vendor}/voice")->assertOk();

        expect($response->json('data.0.id'))->toBe('vv1')->and($response->json('data.0.language'))->toBe('it')
            ->and(array_keys($response->json('data.0')))->toBe(CATALOGUE_ENTRY_MEMBERS);
        Http::assertSent(fn (Request $request) => $request->header($header) === ['vendor-key-123456']);
    })->with([
        'cartesia' => ['cartesia', 'api.cartesia.ai/voices*', ['data' => [['id' => 'vv1', 'name' => 'V', 'language' => 'it']], 'has_more' => false], 'X-API-Key'],
        'elevenlabs' => ['elevenlabs', 'api.elevenlabs.io/v2/voices*', ['voices' => [['voice_id' => 'vv1', 'name' => 'V', 'labels' => ['language' => 'it']]], 'has_more' => false], 'xi-api-key'],
    ]);

    it('answers 404 for a vendor pair that is not a voice listing', function (string $path) {
        catalogueGet($path)->assertNotFound();
    })->with(['/v1/catalogue/cartesia/avatar', '/v1/catalogue/elevenlabs/replica', '/v1/catalogue/cartesia/pal']);

    it('answers 422 for a vendor with no key, 502 when it fails, and caches a good answer', function () {
        Http::fake(['api.cartesia.ai/voices*' => Http::sequence()->push(['detail' => 'vendor-key-123456'], 500)->push(['data' => [['id' => 'vv1', 'name' => 'V']], 'has_more' => false])]);
        catalogueGet('/v1/catalogue/cartesia/voice')->assertStatus(422)->assertJsonPath('code', 'provider_credential_missing');
        Http::assertNothingSent();

        catalogueKey($this->account, 'cartesia', 'vendor-key-123456');
        catalogueGet('/v1/catalogue/cartesia/voice')->assertStatus(502)->assertJsonPath('code', 'provider_error');
        catalogueGet('/v1/catalogue/cartesia/voice')->assertOk();
        catalogueGet('/v1/catalogue/cartesia/voice')->assertOk();

        Http::assertSentCount(2);
    });

    it('lists LiveAvatar avatars and voices', function () {
        catalogueKey($this->account, 'heygen', 'la-key-123456');
        Http::fake(['api.liveavatar.com/v1/*' => Http::response(['code' => 1000, 'data' => ['next' => null, 'results' => [['id' => 'ha1', 'name' => 'Studio', 'language' => 'it', 'status' => 'ACTIVE']]]])]);

        expect(catalogueGet('/v1/catalogue/heygen/avatar')->assertOk()->json('data.0.id'))->toBe('ha1')
            ->and(catalogueGet('/v1/catalogue/heygen/voice')->assertOk()->json('data.0.language'))->toBe('it');
    });

    it('needs the avatar_configs:read scope and a key', function () {
        catalogueGet('/v1/catalogue/tavus/replica', $this->noScope)->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
        $this->getJson('/v1/catalogue/tavus/replica')->assertStatus(401);
    });
});
