<?php

use App\AvatarProviders\ProviderRegistry;
use App\Credentials\ProviderAuth;
use App\Keys\KeyType;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\LlmModel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/*
 * A credential must never appear in a response, a log line or a stored column other than its
 * hash. Each slice that stores or returns a credential adds its case here (captureLogs lives in
 * tests/Pest.php).
 */
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

it('never echoes or logs a key that fails authentication', function () {
    $logged = captureLogs();
    Route::middleware('auth.secret')->get('/v1/_probe', fn () => ['ok' => true]);
    $wrong = 'hra_sk_live_'.str_repeat('f', 96);
    [, $expired] = issueKey(Account::factory()->create(), ['expires_at' => now()->subMinute()]);

    $responses = [
        $this->getJson('/v1/_probe', ['Authorization' => "Bearer {$wrong}"]),
        $this->getJson('/v1/_probe', ['Authorization' => "Bearer {$expired}"]),
        $this->getJson("/v1/_probe?api_key={$wrong}"),
    ];

    foreach ($responses as $response) {
        expect($response->getContent())->not->toContain($wrong)->not->toContain($expired);
    }
    expect(implode("\n", $logged->getArrayCopy()))->not->toContain($wrong)->not->toContain($expired);
});

it('keeps keys issued through the management API out of the logs and out of every later read', function () {
    $logged = captureLogs();
    [, $caller] = issueKey(Account::factory()->create(), ['scopes' => ['*']]);
    $headers = ['Authorization' => "Bearer {$caller}"];

    $created = $this->postJson('/v1/api-keys', ['type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read']], $headers)->assertCreated();
    $rotated = $this->postJson("/v1/api-keys/{$created->json('id')}/rotate", [], $headers)->assertCreated();
    $revoked = $this->postJson("/v1/api-keys/{$created->json('id')}/revoke", [], $headers)->assertOk();
    $listed = $this->getJson('/v1/api-keys', $headers)->assertOk();
    $refused = $this->postJson('/v1/api-keys', ['type' => 'secret', 'mode' => 'live', 'scopes' => ['bogus']], $headers);

    $keys = [$created->json('key'), $rotated->json('key')];
    $hashes = [hash('sha256', $keys[0]), hash('sha256', $keys[1])];

    foreach ([$revoked, $listed, $refused] as $response) {
        foreach ([...$keys, ...$hashes] as $secret) {
            expect($response->getContent())->not->toContain($secret);
        }
    }
    foreach ([...$keys, ...$hashes, $caller] as $secret) {
        expect(implode("\n", $logged->getArrayCopy()))->not->toContain($secret);
    }
});

/*
 * The sweep: one account that stores every kind of secret, then everything it can read, every log line and every
 * queued job, looked through for any of them. A secret appears exactly where it is created (a key and a webhook
 * secret once, in the answer that makes them) and nowhere else.
 */
describe('the sweep', function () {
    beforeEach(function () {
        $this->logged = captureLogs();
        Queue::fake();
        LlmModel::factory()->create(['id' => 'gem-1', 'provider' => 'gemini', 'model' => 'gemini-x']);
        $this->account = Account::factory()->create();
        [, $caller] = issueKey($this->account, ['scopes' => ['*']]);
        $this->caller = $caller;
        [, $this->publishable] = issueKey($this->account, type: KeyType::Publishable);
        $this->headers = ['Authorization' => "Bearer {$caller}"];
        $this->providerKey = 'tv-sweep-provider-key-'.bin2hex(random_bytes(6));
        $this->llmKey = 'gm-sweep-llm-key-'.bin2hex(random_bytes(6));
    });

    /** The one answer a secret may appear in is the one that creates it; every other read of it is listed here. */
    function sweepReads(object $test): array
    {
        $account = $test->account;
        $headers = $test->headers;
        $config = inAccount($account, fn () => AvatarConfig::factory()->create(['provider' => 'tavus', 'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1']]));
        $conversation = inAccount($account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id, 'provider' => 'tavus']));

        $connect = $test->postJson("/v1/client/conversations/{$conversation->id}/connect", [], browserHeaders($test->publishable, clientSecretFor($conversation)));

        return [
            'connect' => $connect,
            'conversation' => $test->getJson("/v1/conversations/{$conversation->id}", $headers),
            'transcript' => $test->getJson("/v1/conversations/{$conversation->id}/transcript", $headers),
            'provider credentials' => $test->getJson('/v1/provider-credentials', $headers),
            'llm credentials' => $test->getJson('/v1/llm-credentials', $headers),
            'api keys' => $test->getJson('/v1/api-keys', $headers),
            'avatar configs' => $test->getJson('/v1/avatar-configs', $headers),
            'avatar config' => $test->getJson("/v1/avatar-configs/{$config->id}", $headers),
            'webhook endpoints' => $test->getJson('/v1/webhook-endpoints', $headers),
            'deliveries' => $test->getJson('/v1/webhook-endpoints/'.$test->endpointId.'/deliveries', $headers),
            'catalogue' => $test->getJson('/v1/catalogue', $headers),
            'llm models' => $test->getJson('/v1/llm-models', $headers),
            'ended' => $test->postJson("/v1/conversations/{$conversation->id}/end", [], $headers),
        ];
    }

    it('shows no stored secret in any answer, log line or queued job', function () {
        $this->putJson('/v1/provider-credentials/tavus', ['api_key' => $this->providerKey], $this->headers)->assertOk();
        $this->putJson('/v1/llm-credentials/gemini', ['api_key' => $this->llmKey], $this->headers)->assertOk();
        $endpoint = $this->postJson('/v1/webhook-endpoints', ['url' => 'https://receiver.example.com/hook'], $this->headers)->assertCreated();
        $issued = $this->postJson('/v1/api-keys', ['type' => 'secret', 'mode' => 'live', 'scopes' => ['conversations:read']], $this->headers)->assertCreated();
        $this->endpointId = $endpoint->json('id');
        Http::fake(['tavusapi.com/*' => Http::response(['conversation_id' => 'c123', 'conversation_url' => 'https://tavus.daily.co/c123', 'status' => 'active'])]);

        $reads = sweepReads($this);

        $callbackUrl = null;
        Http::assertSent(function ($request) use (&$callbackUrl) {
            $callbackUrl = $request->data()['callback_url'] ?? $callbackUrl;

            return true;
        });
        $callbackToken = basename((string) $callbackUrl);
        $secrets = [
            'provider key' => $this->providerKey, 'llm key' => $this->llmKey, 'webhook secret' => $endpoint->json('secret'),
            'issued key' => $issued->json('key'), 'callback token' => $callbackToken, 'caller key' => $this->caller,
        ];
        $jobs = collect(Queue::pushedJobs())->flatten(1)->pluck('job')->map(fn ($job) => serialize($job))->implode("\n");

        expect($callbackToken)->toMatch('/^[0-9a-f]{64}$/')->and($reads['connect']->status())->toBe(200);
        foreach ($secrets as $name => $secret) {
            foreach ($reads as $read => $response) {
                expect($response->getContent())->not->toContain($secret, "{$name} is in the answer of {$read}");
            }
            expect(implode("\n", $this->logged->getArrayCopy()))->not->toContain($secret, "{$name} is in a log line")
                ->and($jobs)->not->toContain($secret, "{$name} is in a queued job");
        }
        expect($jobs)->not->toBeEmpty();
    });

    it('shows no secret when the provider fails and echoes them back', function () {
        $this->putJson('/v1/provider-credentials/tavus', ['api_key' => $this->providerKey], $this->headers)->assertOk();
        $webhookSecret = $this->postJson('/v1/webhook-endpoints', ['url' => 'https://receiver.example.com/hook'], $this->headers)->json('secret');
        Http::fake(['tavusapi.com/*' => Http::response(['message' => "bad key {$this->providerKey} for {$webhookSecret}", 'error' => $this->providerKey], 500)]);
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create(['provider' => 'tavus', 'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1']]));
        $conversation = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id, 'provider' => 'tavus']));

        $response = $this->postJson("/v1/client/conversations/{$conversation->id}/connect", [], browserHeaders($this->publishable, clientSecretFor($conversation)));

        $everything = $response->getContent()."\n".implode("\n", $this->logged->getArrayCopy());
        expect($response->status())->toBe(502)
            ->and($everything)->not->toContain($this->providerKey)->not->toContain($webhookSecret)->not->toContain('bad key');
    });

    it('shows no secret in the exception a failed provider call reports', function () {
        $this->putJson('/v1/provider-credentials/tavus', ['api_key' => $this->providerKey], $this->headers)->assertOk();
        Http::fake(['tavusapi.com/*' => Http::response(['message' => "echo {$this->providerKey}"], 500)]);
        $conversation = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => AvatarConfig::factory()->create(['provider' => 'tavus', 'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1']])->id, 'provider' => 'tavus']));
        $adapter = app(ProviderRegistry::class)->get('tavus');
        $auth = new ProviderAuth($this->providerKey, $this->account->id);
        $messages = [];

        foreach ([fn () => $adapter->fetchSession('c1', $auth), fn () => $adapter->fetchTranscript('c1', $auth), fn () => $adapter->teardown('c1', null, $auth)] as $call) {
            try {
                $call();
            } catch (Throwable $e) {
                $messages[] = $e->getMessage()."\n".$e->getTraceAsString();
            }
        }

        expect($messages)->not->toBeEmpty();
        foreach ($messages as $message) {
            expect($message)->not->toContain('echo');
        }
        expect(implode("\n", $messages))->not->toContain($this->providerKey)->and($conversation->id)->not->toBeEmpty();
    });
});
