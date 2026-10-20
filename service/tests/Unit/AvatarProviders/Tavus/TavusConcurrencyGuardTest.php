<?php

use App\AvatarProviders\FailureClass;
use App\AvatarProviders\ProviderException;
use App\AvatarProviders\Tavus\TavusConcurrencyGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const TAVUS_GUARD_URL = 'https://tavusapi.com/v2/conversations';
const TAVUS_AT_CAPACITY = 'Maximum concurrent conversations reached';

function tavusGuardCall(): Response
{
    return Http::withHeaders(['x-api-key' => 'SECRET_KEY'])->post(TAVUS_GUARD_URL);
}

beforeEach(function () {
    Sleep::fake();
    config(['hr-avatar.tavus.concurrency_retries' => 3, 'hr-avatar.tavus.concurrency_backoff_ms' => 2000]);
});

it('retries a concurrency rejection and returns the response that succeeds', function () {
    Http::fake([TAVUS_GUARD_URL => Http::sequence()
        ->push(['error' => TAVUS_AT_CAPACITY], 400)
        ->push(['conversation_id' => 'c1'], 200)]);

    $response = (new TavusConcurrencyGuard)->attempt(fn () => tavusGuardCall());

    Http::assertSentCount(2);
    expect($response->successful())->toBeTrue();
});

it('waits the configured backoff between attempts, and only between them', function () {
    config(['hr-avatar.tavus.concurrency_backoff_ms' => 750]);
    Http::fake([TAVUS_GUARD_URL => Http::sequence()
        ->push(['error' => TAVUS_AT_CAPACITY], 400)
        ->push(['error' => TAVUS_AT_CAPACITY], 400)
        ->push(['conversation_id' => 'c1'], 200)]);

    (new TavusConcurrencyGuard)->attempt(fn () => tavusGuardCall());

    Sleep::assertSequence([Sleep::for(750)->milliseconds(), Sleep::for(750)->milliseconds()]);
});

it('does not wait when the first attempt succeeds', function () {
    Http::fake([TAVUS_GUARD_URL => Http::response(['conversation_id' => 'c1'], 200)]);

    (new TavusConcurrencyGuard)->attempt(fn () => tavusGuardCall());

    Sleep::assertNeverSlept();
    Http::assertSentCount(1);
});

it('does not sleep at all when the backoff is zero', function () {
    config(['hr-avatar.tavus.concurrency_backoff_ms' => 0]);
    Http::fake([TAVUS_GUARD_URL => Http::response(['error' => TAVUS_AT_CAPACITY], 400)]);

    expect(fn () => (new TavusConcurrencyGuard)->attempt(fn () => tavusGuardCall()))->toThrow(ProviderException::class);

    Sleep::assertNeverSlept();
    Http::assertSentCount(4);
});

it('gives up after the configured retries and raises a throttle', function () {
    config(['hr-avatar.tavus.concurrency_retries' => 2]);
    Http::fake([TAVUS_GUARD_URL => Http::response(['error' => TAVUS_AT_CAPACITY], 400)]);

    try {
        (new TavusConcurrencyGuard)->attempt(fn () => tavusGuardCall());
        $this->fail('Expected a ProviderException');
    } catch (ProviderException $e) {
        expect($e->failureClass())->toBe(FailureClass::Throttle)
            ->and($e->status())->toBe(400)
            ->and($e->getMessage())->toBe('Provider request failed (status 400)');
    }

    Http::assertSentCount(3);
});

it('makes a single attempt when retries are disabled, and still raises the throttle', function () {
    config(['hr-avatar.tavus.concurrency_retries' => 0]);
    Http::fake([TAVUS_GUARD_URL => Http::response(['error' => TAVUS_AT_CAPACITY], 400)]);

    expect(fn () => (new TavusConcurrencyGuard)->attempt(fn () => tavusGuardCall()))->toThrow(ProviderException::class);

    Http::assertSentCount(1);
});

it('treats a negative setting as no retries', function () {
    config(['hr-avatar.tavus.concurrency_retries' => -2]);
    Http::fake([TAVUS_GUARD_URL => Http::response(['error' => TAVUS_AT_CAPACITY], 400)]);

    expect(fn () => (new TavusConcurrencyGuard)->attempt(fn () => tavusGuardCall()))->toThrow(ProviderException::class);

    Http::assertSentCount(1);
});

it('does not retry anything that is not a concurrency rejection', function (int $status, array $body) {
    Http::fake([TAVUS_GUARD_URL => Http::response($body, $status)]);

    $response = (new TavusConcurrencyGuard)->attempt(fn () => tavusGuardCall());

    Http::assertSentCount(1);
    expect($response->status())->toBe($status);
})->with([
    'other 4xx' => [400, ['error' => 'invalid persona_id']],
    'unauthorized' => [401, ['message' => 'Invalid access token']],
    'the capacity text on a 5xx' => [503, ['error' => TAVUS_AT_CAPACITY]],
    'no message' => [400, []],
    'a message that is not a string' => [400, ['message' => ['nested' => TAVUS_AT_CAPACITY]]],
    'a success' => [200, ['conversation_id' => 'c1']],
]);

it('recognises the capacity message in the places Tavus puts it, in any case', function (array $body, bool $limited) {
    Http::fake([TAVUS_GUARD_URL => Http::response($body, 400)]);

    expect((new TavusConcurrencyGuard)->isConcurrencyLimited(tavusGuardCall()))->toBe($limited);
})->with([
    'message' => [['message' => 'MAXIMUM CONCURRENT CONVERSATIONS reached'], true],
    'error' => [['error' => 'maximum concurrent conversations reached'], true],
    'data.message' => [['data' => ['message' => 'Maximum concurrent conversations reached']], true],
    'embedded in a longer text' => [['message' => 'Bad request: you hit the maximum concurrent conversations for your plan.'], true],
    'another message' => [['message' => 'invalid field'], false],
    'an empty JSON body' => [[], false],
]);

it('never lets the response body or the key out of the exception it raises', function () {
    config(['hr-avatar.tavus.concurrency_retries' => 0]);
    Http::fake([TAVUS_GUARD_URL => Http::response(['error' => TAVUS_AT_CAPACITY.' SECRET_KEY'], 400)]);

    try {
        (new TavusConcurrencyGuard)->attempt(fn () => tavusGuardCall());
        $this->fail('Expected a ProviderException');
    } catch (ProviderException $e) {
        expect((string) $e)->not->toContain('SECRET_KEY')->not->toContain('Maximum');
    }
});
