<?php

use App\Models\Account;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->account = Account::factory()->create();
    [, $this->manage] = issueKey($this->account, ['scopes' => ['credentials:manage']]);
    [, $this->read] = issueKey($this->account, ['scopes' => ['llm_models:read']]);
    LlmModel::factory()->create(['id' => 'gem-1', 'provider' => 'gemini', 'model' => 'gemini-x', 'display_name' => 'Gemini X']);
    LlmModel::factory()->create(['id' => 'gem-2', 'provider' => 'gemini', 'model' => 'gemini-y', 'display_name' => 'Gemini Y']);
    LlmModel::factory()->create(['id' => 'old-1', 'provider' => 'oldvendor', 'enabled' => false]);
});

function llmCall(string $method, string $uri, ?string $raw = null, array $body = []): TestResponse
{
    $headers = ['Authorization' => 'Bearer '.($raw ?? test()->manage)];

    return $method === 'get' ? test()->getJson($uri, $headers) : test()->{$method.'Json'}($uri, $body, $headers);
}

describe('GET /v1/llm-models', function () {
    it('lists the enabled catalogue entries and nothing else', function () {
        $response = llmCall('get', '/v1/llm-models', $this->read)->assertOk();

        expect(array_keys($response->json()))->toBe(['data'])
            ->and($response->json('data'))->toBe([
                ['id' => 'gem-1', 'provider' => 'gemini', 'model' => 'gemini-x', 'display_name' => 'Gemini X', 'base_url' => 'https://llm.example.test/v1/', 'enabled' => true],
                ['id' => 'gem-2', 'provider' => 'gemini', 'model' => 'gemini-y', 'display_name' => 'Gemini Y', 'base_url' => 'https://llm.example.test/v1/', 'enabled' => true],
            ]);
    });

    it('is the same catalogue for every account and is not paginated', function () {
        [, $other] = issueKey(Account::factory()->create(), ['scopes' => ['llm_models:read']]);

        expect(llmCall('get', '/v1/llm-models?limit=1', $other)->json('data'))->toHaveCount(2);
    });

    it('offers no way to change the catalogue', function (string $method) {
        $response = llmCall($method, '/v1/llm-models', $this->read, ['id' => 'x', 'provider' => 'x']);

        $response->assertStatus(405);
        expect($response->json('code'))->toBe('method_not_allowed')
            ->and($response->headers->get('Allow'))->toContain('GET');
        expect(LlmModel::count())->toBe(3);
    })->with(['post', 'put', 'patch', 'delete']);

    it('offers no way to change one entry either', function (string $method) {
        llmCall($method, '/v1/llm-models/gem-1', $this->read, ['enabled' => false])->assertStatus(404);
        expect(LlmModel::find('gem-1')->enabled)->toBeTrue();
    })->with(['get', 'put', 'patch', 'delete']);

    it('requires the llm_models:read scope', function () {
        $response = llmCall('get', '/v1/llm-models', $this->manage);

        $response->assertStatus(403);
        expect($response->json('code'))->toBe('insufficient_scope');
    });

    it('requires authentication', function () {
        $this->getJson('/v1/llm-models')->assertStatus(401);
    });
});

describe('LLM credentials', function () {
    it('stores a key, then lists the entry without it', function () {
        $stored = llmCall('put', '/v1/llm-credentials/gemini', body: ['api_key' => 'gm_abcdefgh5678'])->assertOk();

        expect(array_keys($stored->json()))->toBe(['llm_provider', 'last_four', 'updated_at'])
            ->and($stored->json('llm_provider'))->toBe('gemini')
            ->and($stored->json('last_four'))->toBe('5678');

        $listed = llmCall('get', '/v1/llm-credentials')->assertOk();
        expect($listed->json())->toBe(['data' => [['llm_provider' => 'gemini', 'last_four' => '5678']]])
            ->and($listed->getContent())->not->toContain('gm_abcdefgh5678');
    });

    it('stores the key encrypted and replaces it in place', function () {
        llmCall('put', '/v1/llm-credentials/gemini', body: ['api_key' => 'gm_first_key_0001'])->assertOk();
        llmCall('put', '/v1/llm-credentials/gemini', body: ['api_key' => 'gm_second_key_0002'])->assertOk();

        expect(DB::table('llm_credentials')->count())->toBe(1)
            ->and(DB::table('llm_credentials')->value('api_key'))->not->toContain('gm_second_key_0002')
            ->and(inAccount($this->account, fn () => LlmCredential::first()->api_key))->toBe('gm_second_key_0002');
    });

    it('answers 404 for a provider that is not in the catalogue', function () {
        $response = llmCall('put', '/v1/llm-credentials/acme', body: ['api_key' => 'gm_abcdefgh5678']);

        $response->assertStatus(404);
        expect($response->json('code'))->toBe('not_found')
            ->and(DB::table('llm_credentials')->count())->toBe(0);
    });

    it('accepts a provider whose models are all disabled, because it is still in the catalogue', function () {
        llmCall('put', '/v1/llm-credentials/oldvendor', body: ['api_key' => 'ov_abcdefgh5678'])->assertOk();
    });

    it('shows no last four for a short key', function () {
        llmCall('put', '/v1/llm-credentials/gemini', body: ['api_key' => 'short-key'])->assertOk()->assertJsonPath('last_four', null);
    });

    it('refuses a missing, empty or oversize key', function (mixed $key) {
        $response = llmCall('put', '/v1/llm-credentials/gemini', body: ['api_key' => $key]);

        $response->assertStatus(422);
        expect($response->json('errors'))->toHaveKey('api_key');
    })->with([null, '', str_repeat('k', 513)]);

    it('deletes idempotently, even for a provider no longer in the catalogue', function () {
        llmCall('put', '/v1/llm-credentials/gemini', body: ['api_key' => 'gm_abcdefgh5678'])->assertOk();

        llmCall('delete', '/v1/llm-credentials/gemini')->assertNoContent();
        llmCall('delete', '/v1/llm-credentials/gemini')->assertNoContent();
        llmCall('delete', '/v1/llm-credentials/acme')->assertNoContent();

        expect(llmCall('get', '/v1/llm-credentials')->json('data'))->toBe([]);
    });

    it('lists the entries by provider and only the calling account\'s', function () {
        $other = Account::factory()->create();
        [, $otherManage] = issueKey($other, ['scopes' => ['credentials:manage']]);
        llmCall('put', '/v1/llm-credentials/oldvendor', body: ['api_key' => 'ov_abcdefgh1111'])->assertOk();
        llmCall('put', '/v1/llm-credentials/gemini', body: ['api_key' => 'gm_abcdefgh2222'])->assertOk();
        llmCall('put', '/v1/llm-credentials/gemini', $otherManage, ['api_key' => 'gm_other_acct_3333'])->assertOk();

        expect(array_column(llmCall('get', '/v1/llm-credentials')->json('data'), 'llm_provider'))->toBe(['gemini', 'oldvendor'])
            ->and(array_column(llmCall('get', '/v1/llm-credentials')->json('data'), 'last_four'))->toBe(['2222', '1111'])
            ->and(llmCall('get', '/v1/llm-credentials', $otherManage)->json('data'))->toBe([['llm_provider' => 'gemini', 'last_four' => '3333']]);

        llmCall('delete', '/v1/llm-credentials/gemini', $otherManage)->assertNoContent();
        expect(inAccount($this->account, fn () => LlmCredential::where('llm_provider', 'gemini')->exists()))->toBeTrue();
    });

    it('requires the credentials:manage scope', function (string $method, string $uri) {
        $response = llmCall($method, $uri, $this->read, ['api_key' => 'gm_abcdefgh5678']);

        $response->assertStatus(403);
        expect($response->json('code'))->toBe('insufficient_scope');
    })->with([['get', '/v1/llm-credentials'], ['put', '/v1/llm-credentials/gemini'], ['delete', '/v1/llm-credentials/gemini']]);

    it('requires authentication', function () {
        $this->getJson('/v1/llm-credentials')->assertStatus(401);
        $this->putJson('/v1/llm-credentials/gemini', ['api_key' => 'x'])->assertStatus(401);
    });
});
