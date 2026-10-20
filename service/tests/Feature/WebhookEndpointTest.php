<?php

use App\Jobs\DeliverWebhook;
use App\Models\Account;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\WebhookEndpoint;
use App\Webhooks\Outbound\OutboundHostGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const WEBHOOK_ENDPOINT_MEMBERS = ['id', 'url', 'events', 'enabled', 'description', 'created_at', 'updated_at'];
const ALL_EVENTS = ['conversation.live', 'conversation.ended', 'transcript.ready'];

beforeEach(function () {
    $this->account = Account::factory()->create();
    [, $this->raw] = issueKey($this->account, ['scopes' => ['webhook_endpoints:manage']]);
    [, $this->noScope] = issueKey($this->account, ['scopes' => ['conversations:read']]);
});

/**
 * @param  array<string, mixed>  $body
 */
function whCall(string $method, string $uri, array $body = [], ?string $raw = null): TestResponse
{
    $headers = ['Authorization' => 'Bearer '.($raw ?? test()->raw)];

    return $method === 'get' ? test()->getJson($uri, $headers) : test()->{$method.'Json'}($uri, $body, $headers);
}

/**
 * Production behaviour: destinations are vetted, with a resolver that never touches the network.
 *
 * @param  list<string>  $resolved
 */
function enforceDestinations(array $resolved = ['93.184.216.34']): void
{
    app()->instance(OutboundHostGuard::class, new OutboundHostGuard(true, fn () => $resolved));
}

function whEndpoint(array $attributes = [], ?Account $account = null): WebhookEndpoint
{
    return inAccount($account ?? test()->account, fn () => WebhookEndpoint::factory()->create($attributes));
}

describe('POST /v1/webhook-endpoints', function () {
    it('creates an endpoint and returns the signing secret once', function () {
        $response = whCall('post', '/v1/webhook-endpoints', ['url' => 'https://hooks.example.com/hra', 'description' => 'Main'])->assertCreated();

        expect(array_keys($response->json()))->toBe([...WEBHOOK_ENDPOINT_MEMBERS, 'secret'])
            ->and($response->json('id'))->toMatch('/^we_[0-9a-z]{26}$/')
            ->and($response->json('secret'))->toMatch('/^hra_whsec_[0-9a-f]{64}$/')
            ->and($response->json('url'))->toBe('https://hooks.example.com/hra')
            ->and($response->json('events'))->toBe(ALL_EVENTS)
            ->and($response->json('enabled'))->toBeTrue()
            ->and($response->json('description'))->toBe('Main');

        $secret = $response->json('secret');
        foreach (['get', 'patch'] as $method) {
            $again = $method === 'get'
                ? whCall('get', '/v1/webhook-endpoints/'.$response->json('id'))
                : whCall('patch', '/v1/webhook-endpoints/'.$response->json('id'), ['description' => 'Changed']);
            expect($again->assertOk()->getContent())->not->toContain($secret)->not->toContain('secret');
        }
        expect(whCall('get', '/v1/webhook-endpoints')->assertOk()->getContent())->not->toContain($secret)->not->toContain('"secret"');
    });

    it('gives every endpoint a secret of its own', function () {
        $first = whCall('post', '/v1/webhook-endpoints', ['url' => 'https://a.example.com/h'])->json('secret');
        $second = whCall('post', '/v1/webhook-endpoints', ['url' => 'https://a.example.com/h'])->json('secret');

        expect($first)->not->toBe($second);
    });

    it('stores the secret encrypted, readable only through the model', function () {
        $response = whCall('post', '/v1/webhook-endpoints', ['url' => 'https://hooks.example.com/hra'])->assertCreated();
        $secret = $response->json('secret');

        $raw = DB::table('webhook_endpoints')->where('id', $response->json('id'))->value('secret');

        expect($raw)->not->toContain($secret)->and($raw)->not->toContain('hra_whsec_')
            ->and(WebhookEndpoint::withoutGlobalScopes()->find($response->json('id'))->secret)->toBe($secret);
    });

    it('never logs the secret', function () {
        $logs = captureLogs();

        $secret = whCall('post', '/v1/webhook-endpoints', ['url' => 'https://hooks.example.com/hra'])->assertCreated()->json('secret');

        expect(implode("\n", $logs->getArrayCopy()))->not->toContain($secret);
    });

    it('takes the events it is given, once each', function () {
        whCall('post', '/v1/webhook-endpoints', ['url' => 'https://a.example.com/h', 'events' => ['conversation.ended']])->assertCreated()->assertJsonPath('events', ['conversation.ended']);
    });

    it('refuses a body that breaks a rule, names the field and creates nothing', function (string $field, array $body, bool $production = false) {
        $production && enforceDestinations();

        $response = whCall('post', '/v1/webhook-endpoints', $body)->assertStatus(422);

        expect($response->json('code'))->toBe('validation_failed')->and(array_keys($response->json('errors')))->toContain($field)
            ->and(WebhookEndpoint::withoutGlobalScopes()->count())->toBe(0);
    })->with([
        'no url' => ['url', []],
        'url not text' => ['url', ['url' => ['https://a.example.com']]],
        'not a url' => ['url', ['url' => 'hooks']],
        'a url with a space' => ['url', ['url' => 'https://a.example.com/a b']],
        'a url with a control character' => ['url', ['url' => "https://a.example.com/a\x00b"]],
        'embedded credentials' => ['url', ['url' => 'https://user:pass@example.com/hook']],
        'a user without a password' => ['url', ['url' => 'https://user@example.com/hook']],
        'a url of more than 2048 characters' => ['url', ['url' => 'https://example.com/'.str_repeat('a', 2030)]],
        'plain http in production' => ['url', ['url' => 'http://example.com/hook'], true],
        'another scheme' => ['url', ['url' => 'ftp://example.com/hook']],
        'a private address literal in production' => ['url', ['url' => 'https://10.0.0.5/hook'], true],
        'the metadata address in production' => ['url', ['url' => 'https://169.254.169.254/latest'], true],
        'an unknown event' => ['events.0', ['url' => 'https://a.example.com/h', 'events' => ['conversation.exploded']]],
        'no events at all' => ['events', ['url' => 'https://a.example.com/h', 'events' => []]],
        'events not a list' => ['events', ['url' => 'https://a.example.com/h', 'events' => 'conversation.live']],
        'a repeated event' => ['events.1', ['url' => 'https://a.example.com/h', 'events' => ['conversation.live', 'conversation.live']]],
        'enabled not a boolean' => ['enabled', ['url' => 'https://a.example.com/h', 'enabled' => 'maybe']],
        'a description of more than 255 characters' => ['description', ['url' => 'https://a.example.com/h', 'description' => str_repeat('a', 256)]],
    ]);

    it('accepts a url of 2048 characters', function () {
        $url = 'https://example.com/'.str_repeat('a', 2048 - strlen('https://example.com/'));
        expect(strlen($url))->toBe(2048);

        whCall('post', '/v1/webhook-endpoints', ['url' => $url])->assertCreated();
    });

    it('accepts plain http only where destinations are not enforced', function () {
        whCall('post', '/v1/webhook-endpoints', ['url' => 'http://localhost:9000/hook'])->assertCreated();

        enforceDestinations();
        whCall('post', '/v1/webhook-endpoints', ['url' => 'http://example.com/hook'])->assertStatus(422);
    });

    it('accepts a public destination in production, and a host that does not resolve', function () {
        enforceDestinations();
        whCall('post', '/v1/webhook-endpoints', ['url' => 'https://hooks.example.com/hook'])->assertCreated();

        enforceDestinations([]);
        whCall('post', '/v1/webhook-endpoints', ['url' => 'https://not-yet.example.com/hook'])->assertCreated();
    });

    it('refuses a host that resolves to a private address in production', function () {
        enforceDestinations(['10.1.2.3']);

        $response = whCall('post', '/v1/webhook-endpoints', ['url' => 'https://rebind.example.com/hook'])->assertStatus(422);

        expect($response->json('errors.url'))->toBeArray()->and(WebhookEndpoint::withoutGlobalScopes()->count())->toBe(0);
    });

    it('refuses the 21st endpoint of an account and allows another account its own 20', function () {
        foreach (range(1, 20) as $i) {
            whCall('post', '/v1/webhook-endpoints', ['url' => "https://a.example.com/{$i}"])->assertCreated();
        }

        $response = whCall('post', '/v1/webhook-endpoints', ['url' => 'https://a.example.com/21'])->assertStatus(422);
        expect($response->json('code'))->toBe('validation_failed')->and($response->json('detail'))->toContain('20')
            ->and(inAccount($this->account, fn () => WebhookEndpoint::query()->count()))->toBe(20);

        [, $other] = issueKey(Account::factory()->create(), ['scopes' => ['webhook_endpoints:manage']]);
        whCall('post', '/v1/webhook-endpoints', ['url' => 'https://b.example.com/1'], $other)->assertCreated();
    });

    it('counts the limit after a delete, so a slot frees up', function () {
        $ids = array_map(fn (int $i) => whCall('post', '/v1/webhook-endpoints', ['url' => "https://a.example.com/{$i}"])->json('id'), range(1, 20));
        whCall('delete', '/v1/webhook-endpoints/'.$ids[0])->assertNoContent();

        whCall('post', '/v1/webhook-endpoints', ['url' => 'https://a.example.com/new'])->assertCreated();
    });

    it('takes the account row lock before it counts, so two creates cannot both take the last slot', function () {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        whCall('post', '/v1/webhook-endpoints', ['url' => 'https://a.example.com/h'])->assertCreated();

        $lock = array_find_key($queries, fn (string $sql) => str_contains($sql, 'from "accounts"') && str_contains($sql, 'for update'));
        $count = array_find_key($queries, fn (string $sql) => str_contains($sql, 'count(*)') && str_contains($sql, 'webhook_endpoints'));
        expect($lock)->not->toBeNull()->and($count)->not->toBeNull()->and($lock)->toBeLessThan($count);
    });

    it('ignores what the host may not choose', function () {
        $other = Account::factory()->create();

        $response = whCall('post', '/v1/webhook-endpoints', ['url' => 'https://a.example.com/h', 'account_id' => $other->id, 'id' => 'we_chosen', 'secret' => 'hra_whsec_mine'])->assertCreated();

        $stored = WebhookEndpoint::withoutGlobalScopes()->find($response->json('id'));
        expect($stored->account_id)->toBe($this->account->id)->and($stored->id)->not->toBe('we_chosen')->and($stored->secret)->not->toBe('hra_whsec_mine');
    });

    it('needs the manage scope and a key', function () {
        whCall('post', '/v1/webhook-endpoints', ['url' => 'https://a.example.com/h'], $this->noScope)->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
        $this->postJson('/v1/webhook-endpoints', ['url' => 'https://a.example.com/h'])->assertStatus(401);
        expect(WebhookEndpoint::withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('GET /v1/webhook-endpoints', function () {
    it('lists the account\'s endpoints newest first, paginated', function () {
        $first = whEndpoint(['created_at' => now()->subMinutes(3)]);
        $second = whEndpoint(['created_at' => now()->subMinutes(2)]);
        $third = whEndpoint(['created_at' => now()->subMinute()]);
        whEndpoint([], Account::factory()->create());

        $page = whCall('get', '/v1/webhook-endpoints?limit=2')->assertOk();

        expect(array_column($page->json('data'), 'id'))->toBe([$third->id, $second->id])
            ->and($page->json('next_cursor'))->not->toBeNull()
            ->and(array_keys($page->json('data.0')))->toBe(WEBHOOK_ENDPOINT_MEMBERS);

        $next = whCall('get', '/v1/webhook-endpoints?limit=2&cursor='.$page->json('next_cursor'))->assertOk();
        expect(array_column($next->json('data'), 'id'))->toBe([$first->id])->and($next->json('next_cursor'))->toBeNull();
    });

    it('is empty for an account with none, and needs the scope', function () {
        expect(whCall('get', '/v1/webhook-endpoints')->assertOk()->json())->toBe(['data' => [], 'next_cursor' => null]);
        whCall('get', '/v1/webhook-endpoints', [], $this->noScope)->assertStatus(403);
    });
});

describe('GET, PATCH and DELETE /v1/webhook-endpoints/{id}', function () {
    it('shows an endpoint', function () {
        $endpoint = whEndpoint(['url' => 'https://a.example.com/h', 'events' => ['transcript.ready'], 'enabled' => false, 'description' => 'Off']);

        $response = whCall('get', "/v1/webhook-endpoints/{$endpoint->id}")->assertOk();

        expect(array_keys($response->json()))->toBe(WEBHOOK_ENDPOINT_MEMBERS)
            ->and($response->json('events'))->toBe(['transcript.ready'])->and($response->json('enabled'))->toBeFalse()->and($response->json('description'))->toBe('Off');
    });

    it('changes the url, events, enabled flag and description, leaving what is not sent', function () {
        $endpoint = whEndpoint(['url' => 'https://a.example.com/h', 'description' => 'Keep']);
        $secret = $endpoint->secret;

        $response = whCall('patch', "/v1/webhook-endpoints/{$endpoint->id}", ['url' => 'https://b.example.com/h2', 'events' => ['conversation.live'], 'enabled' => false])->assertOk();

        expect($response->json('url'))->toBe('https://b.example.com/h2')->and($response->json('events'))->toBe(['conversation.live'])
            ->and($response->json('enabled'))->toBeFalse()->and($response->json('description'))->toBe('Keep');
        $stored = WebhookEndpoint::withoutGlobalScopes()->find($endpoint->id);
        expect($stored->secret)->toBe($secret);
    });

    it('can clear the description', function () {
        $endpoint = whEndpoint(['description' => 'x']);

        whCall('patch', "/v1/webhook-endpoints/{$endpoint->id}", ['description' => null])->assertOk()->assertJsonPath('description', null);
    });

    it('checks a new url like a new endpoint\'s, and leaves the endpoint as it was when it is refused', function () {
        enforceDestinations(['10.0.0.9']);
        $endpoint = whEndpoint(['url' => 'https://a.example.com/h']);

        whCall('patch', "/v1/webhook-endpoints/{$endpoint->id}", ['url' => 'https://rebind.example.com/h'])->assertStatus(422);
        whCall('patch', "/v1/webhook-endpoints/{$endpoint->id}", ['url' => 'https://u:p@example.com/h'])->assertStatus(422);
        whCall('patch', "/v1/webhook-endpoints/{$endpoint->id}", ['events' => ['nope']])->assertStatus(422);

        expect(WebhookEndpoint::withoutGlobalScopes()->find($endpoint->id)->url)->toBe('https://a.example.com/h');
    });

    it('does not look at the url again when something else changes', function () {
        enforceDestinations(['10.0.0.9']);
        $endpoint = whEndpoint(['url' => 'https://a.example.com/h']);

        whCall('patch', "/v1/webhook-endpoints/{$endpoint->id}", ['enabled' => false])->assertOk();
    });

    it('does not let a patch change the secret or the owner', function () {
        $endpoint = whEndpoint();
        $secret = $endpoint->secret;

        whCall('patch', "/v1/webhook-endpoints/{$endpoint->id}", ['secret' => 'hra_whsec_mine', 'account_id' => Account::factory()->create()->id, 'id' => 'we_other'])->assertOk();

        $stored = WebhookEndpoint::withoutGlobalScopes()->find($endpoint->id);
        expect($stored->secret)->toBe($secret)->and($stored->account_id)->toBe($this->account->id);
    });

    it('deletes an endpoint', function () {
        $endpoint = whEndpoint();

        whCall('delete', "/v1/webhook-endpoints/{$endpoint->id}")->assertNoContent();

        expect(WebhookEndpoint::withoutGlobalScopes()->find($endpoint->id))->toBeNull();
        whCall('get', "/v1/webhook-endpoints/{$endpoint->id}")->assertNotFound();
    });

    // The table only requires a non-empty array, so a row can name an event this version does not know (a rolled-back
    // deploy, a retired event). One such row must not turn the account's reads into 500s.
    it('leaves out an event name it does not know instead of failing the response', function () {
        $endpoint = whEndpoint(['url' => 'https://a.example.com/h']);
        DB::table('webhook_endpoints')->where('id', $endpoint->id)->update(['events' => json_encode(['conversation.live', 'retired.event', 'transcript.ready'])]);
        $onlyUnknown = whEndpoint(['url' => 'https://b.example.com/h']);
        DB::table('webhook_endpoints')->where('id', $onlyUnknown->id)->update(['events' => json_encode(['retired.event'])]);

        $shown = whCall('get', "/v1/webhook-endpoints/{$endpoint->id}")->assertOk();
        $listed = whCall('get', '/v1/webhook-endpoints')->assertOk();
        $updated = whCall('patch', "/v1/webhook-endpoints/{$endpoint->id}", ['description' => 'still works'])->assertOk();

        expect($shown->json('events'))->toBe(['conversation.live', 'transcript.ready'])
            ->and($updated->json('events'))->toBe(['conversation.live', 'transcript.ready'])
            ->and(collect($listed->json('data'))->firstWhere('id', $onlyUnknown->id)['events'])->toBe([]);
    });

    // The contract says deliveries still waiting for a retry stop with the endpoint. The foreign key cascades, so the
    // row is gone: the job that was to retry it finds nothing, and so does the relay.
    it('stops the deliveries still waiting for a retry', function () {
        Queue::fake();
        Http::fake();
        $endpoint = whEndpoint(['url' => 'https://a.example.com/h']);
        $conversation = inAccount($this->account, fn () => Conversation::factory()->create(['avatar_config_id' => AvatarConfig::factory()->create()->id]));
        $deliveryId = strtolower((string) Str::ulid());
        DB::table('webhook_deliveries')->insert([
            'id' => $deliveryId, 'account_id' => $this->account->id, 'webhook_endpoint_id' => $endpoint->id, 'conversation_id' => $conversation->id,
            'event_id' => 'evt_x', 'event_type' => 'conversation.ended', 'payload' => '{}', 'status' => 'pending', 'attempts' => 2,
            'next_attempt_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        whCall('delete', "/v1/webhook-endpoints/{$endpoint->id}")->assertNoContent();
        app()->call([new DeliverWebhook($this->account->id, $deliveryId), 'handle']);
        Artisan::call('hra:relay');

        expect(DB::table('webhook_deliveries')->where('id', $deliveryId)->exists())->toBeFalse();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    });

    it('answers 404 to another account, for a read, a patch and a delete, and changes nothing', function () {
        $foreign = whEndpoint(['url' => 'https://theirs.example.com/h'], Account::factory()->create());

        whCall('get', "/v1/webhook-endpoints/{$foreign->id}")->assertNotFound()->assertJsonPath('code', 'not_found');
        whCall('patch', "/v1/webhook-endpoints/{$foreign->id}", ['url' => 'https://mine.example.com/h'])->assertNotFound();
        whCall('delete', "/v1/webhook-endpoints/{$foreign->id}")->assertNotFound();

        expect(WebhookEndpoint::withoutGlobalScopes()->find($foreign->id)->url)->toBe('https://theirs.example.com/h');
        whCall('get', '/v1/webhook-endpoints/we_01j0000000000000000000000x')->assertNotFound();
        whCall('get', '/v1/webhook-endpoints/anything')->assertNotFound();
    });

    it('needs the manage scope and a key', function () {
        $endpoint = whEndpoint();

        whCall('get', "/v1/webhook-endpoints/{$endpoint->id}", [], $this->noScope)->assertStatus(403);
        whCall('patch', "/v1/webhook-endpoints/{$endpoint->id}", ['enabled' => false], $this->noScope)->assertStatus(403);
        whCall('delete', "/v1/webhook-endpoints/{$endpoint->id}", [], $this->noScope)->assertStatus(403);
        $this->deleteJson("/v1/webhook-endpoints/{$endpoint->id}")->assertStatus(401);
        expect(WebhookEndpoint::withoutGlobalScopes()->find($endpoint->id))->not->toBeNull();
    });
});

describe('the model', function () {
    it('hides the secret from serialization and encrypts it', function () {
        $endpoint = whEndpoint();

        expect(json_encode($endpoint))->not->toContain($endpoint->secret)->and($endpoint->toArray())->not->toHaveKey('secret')
            ->and($endpoint->secret)->toMatch('/^hra_whsec_[0-9a-f]{64}$/');
    });

    it('does not let mass assignment set the owner, the id or the secret', function () {
        $endpoint = new WebhookEndpoint(['url' => 'https://a.example.com/h', 'account_id' => 'x', 'secret' => 'y', 'id' => 'we_z']);

        expect($endpoint->account_id)->toBeNull()->and($endpoint->secret)->toBeNull()->and($endpoint->url)->toBe('https://a.example.com/h');
    });
});
