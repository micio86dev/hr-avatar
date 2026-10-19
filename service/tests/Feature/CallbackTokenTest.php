<?php

use App\AvatarProviders\Ingestion;
use App\Keys\KeyType;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ProviderCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AvatarProviders\ScriptedProvider;

beforeEach(function () {
    Queue::fake();
    $this->account = Account::factory()->create();
    [, $this->pk] = issueKey($this->account, type: KeyType::Publishable);
});

/**
 * Connects a new conversation on a scripted provider with the given ingestion; returns it with the response body.
 *
 * @return array{0: Conversation, 1: string}
 */
function connectWith(Ingestion $ingestion): array
{
    ScriptedProvider::register();
    ScriptedProvider::$ingestion = $ingestion;
    $config = inAccount(test()->account, fn () => AvatarConfig::factory()->create(['provider' => 'scripted']));
    $conversation = inAccount(test()->account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id, 'provider' => 'scripted']));

    $response = test()->postJson("/v1/client/conversations/{$conversation->id}/connect", [], browserHeaders(test()->pk, clientSecretFor($conversation)))->assertOk();

    return [$conversation, $response->getContent()];
}

/** The callback target the adapter was last handed. */
function lastCallbackUrl(): ?string
{
    return collect(ScriptedProvider::$calls)->last(fn (array $call) => $call[0] === 'start')[2] ?? null;
}

function tokenOf(string $callbackUrl): string
{
    return basename($callbackUrl);
}

describe('for a provider that calls back', function () {
    it('hands the adapter a target made of the public base url, the provider and a 64-hex token', function () {
        connectWith(Ingestion::Callback);

        expect(lastCallbackUrl())->toMatch('#^https://hra\.example\.test/v1/webhooks/providers/scripted/[0-9a-f]{64}$#');
    });

    it('stores only the sha256 of the token', function () {
        [$conversation] = connectWith(Ingestion::Callback);

        $stored = Conversation::withoutGlobalScopes()->findOrFail($conversation->id);
        expect($stored->callback_token_hash)->toBe(hash('sha256', tokenOf((string) lastCallbackUrl())))
            ->and($stored->callback_token_hash)->not->toBe(tokenOf((string) lastCallbackUrl()));
    });

    it('has the hash in place before the provider is called, so no callback can beat it', function () {
        ScriptedProvider::register();
        ScriptedProvider::$ingestion = Ingestion::Callback;
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create(['provider' => 'scripted']));
        $conversation = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id, 'provider' => 'scripted']));
        $seen = null;
        ScriptedProvider::$starts = [function (string $id, $spec, $config, $auth, ?string $callbackUrl) use (&$seen) {
            $seen = [Conversation::withoutGlobalScopes()->findOrFail($id)->callback_token_hash, $callbackUrl];

            return null;
        }];

        $this->postJson("/v1/client/conversations/{$conversation->id}/connect", [], browserHeaders($this->pk, clientSecretFor($conversation)))->assertOk();

        expect($seen[0])->toBe(hash('sha256', tokenOf($seen[1])));
    });

    it('gives each conversation a token of its own', function () {
        connectWith(Ingestion::Callback);
        $first = lastCallbackUrl();
        connectWith(Ingestion::Callback);

        expect(lastCallbackUrl())->not->toBe($first)->and(tokenOf((string) lastCallbackUrl()))->not->toBe(tokenOf((string) $first));
    });

    it('builds the target from the configured base url whatever its trailing slash', function (string $base) {
        config()->set('hr-avatar.public_base_url', $base);

        connectWith(Ingestion::Callback);

        expect(lastCallbackUrl())->toStartWith('https://hra.example.test/v1/webhooks/providers/scripted/');
    })->with(['https://hra.example.test', 'https://hra.example.test/']);

    it('keeps the plain token out of the response, the logs and every column of every table', function () {
        $logs = captureLogs();

        [, $body] = connectWith(Ingestion::Callback);

        $token = tokenOf((string) lastCallbackUrl());
        $tables = DB::select('select table_name from information_schema.tables where table_schema = current_schema() and table_type = ?', ['BASE TABLE']);
        $found = [];
        foreach ($tables as $table) {
            $rows = DB::select(sprintf('select t::text as row from "%s" t', $table->table_name));
            if (collect($rows)->contains(fn ($row) => str_contains($row->row, $token))) {
                $found[] = $table->table_name;
            }
        }

        expect($found)->toBe([])
            ->and($body)->not->toContain($token)
            ->and(collect($logs)->contains(fn (string $line) => str_contains($line, $token)))->toBeFalse();
    });

    it('reaches Tavus as the callback_url of its create request', function () {
        inAccount($this->account, fn () => ProviderCredential::create(['provider' => 'tavus', 'api_key' => 'tv-key-123456']));
        Http::fake(['tavusapi.com/*' => Http::response(['conversation_id' => 'c123', 'conversation_url' => 'https://tavus.daily.co/c123', 'status' => 'active'])]);
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create(['provider' => 'tavus', 'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1']]));
        $conversation = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id, 'provider' => 'tavus']));

        $this->postJson("/v1/client/conversations/{$conversation->id}/connect", [], browserHeaders($this->pk, clientSecretFor($conversation)))->assertOk();

        $sent = null;
        Http::assertSent(function ($request) use (&$sent) {
            $sent = $request->data()['callback_url'] ?? null;

            return true;
        });
        expect($sent)->toMatch('#^https://hra\.example\.test/v1/webhooks/providers/tavus/[0-9a-f]{64}$#')
            ->and(Conversation::withoutGlobalScopes()->findOrFail($conversation->id)->callback_token_hash)->toBe(hash('sha256', tokenOf($sent)));
    });
});

describe('for a provider that does not call back', function () {
    it('passes no target and keeps no token', function (Ingestion $ingestion) {
        [$conversation] = connectWith($ingestion);

        expect(lastCallbackUrl())->toBeNull()
            ->and(Conversation::withoutGlobalScopes()->findOrFail($conversation->id)->callback_token_hash)->toBeNull();
    })->with([Ingestion::Poll, Ingestion::None]);

    it('keeps none for the Mock provider either', function () {
        $config = inAccount($this->account, fn () => AvatarConfig::factory()->create());
        $conversation = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => $config->id]));

        $this->postJson("/v1/client/conversations/{$conversation->id}/connect", [], browserHeaders($this->pk, clientSecretFor($conversation)))->assertOk();

        expect(Conversation::withoutGlobalScopes()->findOrFail($conversation->id)->callback_token_hash)->toBeNull();
    });
});
