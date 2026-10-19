<?php

use App\Credentials\ProviderAuth;
use App\Models\Account;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\ProviderCredential;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

const PLAIN_PROVIDER_KEY = 'tv_plaintext_provider_key_7777';
const PLAIN_LLM_KEY = 'gm_plaintext_llm_key_8888';

/** A job that holds a stored credential, the way a careless one would. */
class HoldsCredentialModelJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ProviderCredential $credential) {}

    public function handle(): void {}
}

/** A job that holds a resolved key. It must be impossible to queue. */
class HoldsProviderAuthJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public ProviderAuth $auth) {}

    public function handle(): void {}
}

beforeEach(function () {
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['credentials:manage', 'llm_models:read']]);
    LlmModel::factory()->create(['provider' => 'gemini']);
});

it('leaves the key out of a stored credential\'s array and JSON form', function (string $model, array $attributes, string $plaintext) {
    $credential = inAccount($this->account, fn () => $model::create([...$attributes, 'api_key' => $plaintext]));

    expect($credential->toArray())->not->toHaveKey('api_key')
        ->and($credential->toJson())->not->toContain($plaintext)->not->toContain('api_key')
        ->and(json_encode($credential))->not->toContain($plaintext)
        ->and(json_encode([$credential]))->not->toContain($plaintext);
})->with([
    'provider credential' => [ProviderCredential::class, ['provider' => 'tavus'], PLAIN_PROVIDER_KEY],
    'LLM credential' => [LlmCredential::class, ['llm_provider' => 'gemini'], PLAIN_LLM_KEY],
]);

it('queues a stored credential as an identifier, never as the key', function () {
    $credential = inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => PLAIN_PROVIDER_KEY]));

    $payload = serialize(new HoldsCredentialModelJob($credential));

    expect($payload)->not->toContain(PLAIN_PROVIDER_KEY)->not->toContain(base64_encode(PLAIN_PROVIDER_KEY))->toContain($credential->id);
});

it('cannot queue a resolved key at all', function () {
    $job = new HoldsProviderAuthJob(new ProviderAuth(PLAIN_PROVIDER_KEY, 'acct'));

    // The queue wraps the refusal; the cause is the key's own, and nothing was pushed.
    try {
        dispatch($job);
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getPrevious())->toBeInstanceOf(LogicException::class)
        ->and($thrown->getMessage())->not->toContain(PLAIN_PROVIDER_KEY);
});

it('keeps the key out of every response and every log line across the credential endpoints', function () {
    $logged = captureLogs();
    $headers = ['Authorization' => "Bearer {$this->raw}"];

    $responses = [
        $this->putJson('/v1/provider-credentials/tavus', ['api_key' => PLAIN_PROVIDER_KEY], $headers),
        $this->putJson('/v1/provider-credentials/tavus', ['api_key' => PLAIN_PROVIDER_KEY.'-replaced'], $headers),
        $this->getJson('/v1/provider-credentials', $headers),
        $this->putJson('/v1/provider-credentials/tavus', ['api_key' => ''], $headers),
        $this->putJson('/v1/provider-credentials/tavus', ['api_key' => str_repeat(PLAIN_PROVIDER_KEY, 40)], $headers),
        $this->putJson('/v1/llm-credentials/gemini', ['api_key' => PLAIN_LLM_KEY], $headers),
        $this->getJson('/v1/llm-credentials', $headers),
        $this->getJson('/v1/llm-models', $headers),
        $this->putJson('/v1/llm-credentials/acme', ['api_key' => PLAIN_LLM_KEY], $headers),
        $this->deleteJson('/v1/provider-credentials/tavus', [], $headers),
        $this->deleteJson('/v1/llm-credentials/gemini', [], $headers),
    ];

    $secrets = [PLAIN_PROVIDER_KEY, PLAIN_LLM_KEY, substr(PLAIN_PROVIDER_KEY, 0, 18), substr(PLAIN_LLM_KEY, 0, 15)];
    foreach ($responses as $response) {
        foreach ($secrets as $secret) {
            expect($response->getContent())->not->toContain($secret);
        }
    }
    foreach ($secrets as $secret) {
        expect(implode("\n", $logged->getArrayCopy()))->not->toContain($secret);
    }
});

it('keeps the key out of the answer and the logs when the write itself blows up', function () {
    $logged = captureLogs();
    ProviderCredential::saving(fn () => throw new RuntimeException('storage failed'));

    $response = $this->putJson('/v1/provider-credentials/tavus', ['api_key' => PLAIN_PROVIDER_KEY], ['Authorization' => "Bearer {$this->raw}"]);

    $response->assertStatus(500);
    expect($response->json('code'))->toBe('internal_error')
        ->and($response->getContent())->not->toContain(PLAIN_PROVIDER_KEY)->not->toContain('storage failed')
        ->and(implode("\n", $logged->getArrayCopy()))->toContain('storage failed')->not->toContain(PLAIN_PROVIDER_KEY);
});

it('keeps the plaintext out of the database apart from the encrypted columns', function () {
    $this->putJson('/v1/provider-credentials/tavus', ['api_key' => PLAIN_PROVIDER_KEY], ['Authorization' => "Bearer {$this->raw}"])->assertOk();
    $this->putJson('/v1/llm-credentials/gemini', ['api_key' => PLAIN_LLM_KEY], ['Authorization' => "Bearer {$this->raw}"])->assertOk();

    foreach (['provider_credentials', 'llm_credentials'] as $table) {
        expect(json_encode(DB::table($table)->get()))->not->toContain('plaintext');
    }
});

it('documents no response that could carry a stored key', function () {
    $schemas = json_decode((string) file_get_contents(base_path('openapi.json')), true)['components']['schemas'];

    foreach ($schemas as $name => $schema) {
        if (str_ends_with($name, 'Resource')) {
            expect(array_keys($schema['properties'] ?? []))->not->toContain('api_key')->not->toContain('key_hash');
        }
    }
    expect(array_keys($schemas['ProviderCredentialResource']['properties']))->toBe(['provider', 'source', 'last_four', 'updated_at'])
        ->and(array_keys($schemas['StoredLlmCredentialResource']['properties']))->toBe(['llm_provider', 'last_four', 'updated_at']);
});
