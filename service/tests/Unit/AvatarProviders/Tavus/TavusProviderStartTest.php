<?php

use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\FailureClass;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\Tavus\TavusProvider;
use App\Credentials\ProviderAuth;
use App\Models\AvatarConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const TAVUS_START_URL = 'https://tavusapi.com/v2/conversations';
const TAVUS_START_KEY = 'tvs_live_SUPER_SECRET_KEY_99999';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00', 'UTC'));
    Sleep::fake();
    config(['hr-avatar.tavus.concurrency_retries' => 3, 'hr-avatar.tavus.concurrency_backoff_ms' => 2000]);

    $this->auth = new ProviderAuth(TAVUS_START_KEY, 'acc_1');
    $this->config = new AvatarConfig(['name' => 'Tavus', 'provider' => 'tavus', 'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1']]);
    $this->spec = new ConversationSpec('Be kind.', 'Hello!', 'it', 600, new stdClass);
});

function tavusStart(?string $callbackUrl = 'https://hra.example.test/webhooks/providers/tavus/TOKEN')
{
    return (new TavusProvider)->start('conv_abc', test()->spec, test()->config, test()->auth, $callbackUrl);
}

function tavusCreated(string $id = 'c123', string $url = 'https://tavus.daily.co/c123')
{
    return Http::response(['conversation_id' => $id, 'conversation_url' => $url, 'status' => 'active'], 200);
}

it('is a callback provider', function () {
    expect((new TavusProvider)->ingestion())->toBe(Ingestion::Callback);
});

it('creates the conversation with one authenticated POST carrying the mapped body', function () {
    Http::fake([TAVUS_START_URL => tavusCreated()]);

    tavusStart();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === TAVUS_START_URL
        && $request->header('x-api-key') === [TAVUS_START_KEY]
        && $request->data() === [
            'conversational_context' => 'Be kind.',
            'custom_greeting' => 'Hello!',
            'callback_url' => 'https://hra.example.test/webhooks/providers/tavus/TOKEN',
            'replica_id' => 'r_1',
            'persona_id' => 'p_1',
            'properties' => ['language' => 'italian', 'max_call_duration' => 600],
        ]);
});

it('does not send the callback url when none is supplied, and never the conversation id', function () {
    Http::fake([TAVUS_START_URL => tavusCreated()]);

    tavusStart(null);

    Http::assertSent(fn (Request $request) => ! array_key_exists('callback_url', $request->data())
        && ! str_contains((string) json_encode($request->data()), 'conv_abc'));
});

it('returns a daily session joined by URL, with the provider conversation id as its ref', function () {
    Http::fake([TAVUS_START_URL => tavusCreated('c123', 'https://tavus.daily.co/c123')]);

    $session = tavusStart();

    expect($session->sessionRef)->toBe('c123')
        ->and($session->contextRef)->toBeNull()
        ->and($session->joinMaterial->provider)->toBe('tavus')
        ->and($session->joinMaterial->transport)->toBe('daily')
        ->and($session->joinMaterial->conversationUrl)->toBe('https://tavus.daily.co/c123')
        ->and($session->joinMaterial->sessionToken)->toBeNull()
        ->and($session->joinMaterial->expiresAt->format('Y-m-d\TH:i:s'))->toBe('2026-10-19T12:10:00');
});

it('retries once the account is at capacity and then succeeds', function () {
    Http::fake([TAVUS_START_URL => Http::sequence()
        ->push(['error' => 'Maximum concurrent conversations reached'], 400)
        ->push(['conversation_id' => 'c9', 'conversation_url' => 'https://tavus.daily.co/c9'], 200)]);

    expect(tavusStart()->sessionRef)->toBe('c9');

    Http::assertSentCount(2);
});

it('raises a throttle when the account stays at capacity, after the configured retries', function () {
    Http::fake([TAVUS_START_URL => Http::response(['message' => 'Maximum concurrent conversations reached'], 400)]);

    try {
        tavusStart();
        $this->fail('Expected a ProviderException');
    } catch (ProviderException $e) {
        expect($e->failureClass())->toBe(FailureClass::Throttle);
    }

    Http::assertSentCount(4);
});

it('classifies a refusal by its status and does not retry it', function (int $status, FailureClass $class) {
    Http::fake([TAVUS_START_URL => Http::response(['message' => 'nope'], $status)]);

    try {
        tavusStart();
        $this->fail('Expected a ProviderException');
    } catch (ProviderException $e) {
        expect($e->failureClass())->toBe($class)
            ->and($e->status())->toBe($status)
            ->and($e->getMessage())->toBe("Provider request failed (status {$status})");
    }

    Http::assertSentCount(1);
})->with([
    'bad request' => [400, FailureClass::ClientError],
    'unauthorized' => [401, FailureClass::ClientError],
    'rate limited' => [429, FailureClass::Throttle],
    'server error' => [500, FailureClass::Upstream],
    'unavailable' => [503, FailureClass::Upstream],
]);

it('refuses a success that lacks the conversation id or url instead of handing out half a session', function (array $body) {
    Http::fake([TAVUS_START_URL => Http::response($body, 200)]);

    expect(fn () => tavusStart())->toThrow(ProviderException::class, 'Provider request failed (no response)');
})->with([
    'no url' => [['conversation_id' => 'c1']],
    'no id' => [['conversation_url' => 'https://tavus.daily.co/c1']],
    'empty ids' => [['conversation_id' => '', 'conversation_url' => '']],
    'not strings' => [['conversation_id' => 7, 'conversation_url' => ['x']]],
    'empty body' => [[]],
]);

it('reports a transport failure as no response, and does not retry it', function () {
    $attempts = 0;
    Http::fake([TAVUS_START_URL => function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 28: timed out');
    }]);

    try {
        tavusStart();
        $this->fail('Expected a ProviderException');
    } catch (ProviderException $e) {
        expect($e->failureClass())->toBe(FailureClass::Upstream)
            ->and($e->status())->toBeNull()
            ->and($e->getMessage())->toBe('Provider request failed (no response)')
            ->and($e->getPrevious())->toBeNull();
    }

    expect($attempts)->toBe(1);
});
