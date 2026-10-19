<?php

use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\Mock\MockProvider;
use App\AvatarProviders\TranscriptLine;
use App\Credentials\ProviderAuth;
use App\Models\AvatarConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00', 'UTC'));
    Cache::flush();
    $this->mock = new MockProvider;
    $this->auth = new ProviderAuth('not-used-by-the-mock', ProviderAuth::PLATFORM);
    $this->config = new AvatarConfig(['name' => 'Mock', 'provider' => 'mock']);
});

function mockSpec(?string $opening = 'Hello there!'): ConversationSpec
{
    return new ConversationSpec('Be kind.', $opening, 'en', 600, new stdClass);
}

function startMock(string $conversationId = 'conv_abc', ?string $opening = 'Hello there!')
{
    return test()->mock->start($conversationId, mockSpec($opening), test()->config, test()->auth, null);
}

it('starts with deterministic refs, URL, token and an expiry one hour out', function () {
    $session = startMock('conv_abc');

    expect($session->sessionRef)->toBe('mock_conv_abc')
        ->and($session->contextRef)->toBeNull()
        ->and($session->joinMaterial->provider)->toBe('mock')
        ->and($session->joinMaterial->transport)->toBe('mock')
        ->and($session->joinMaterial->conversationUrl)->toBe('mock://conversation/conv_abc')
        ->and($session->joinMaterial->sessionToken)->toBe('mock_token_conv_abc')
        ->and($session->joinMaterial->expiresAt->format('Y-m-d\TH:i:s'))->toBe('2026-10-19T13:00:00');
});

it('gives identical results for the same conversation and clock', function () {
    expect(startMock('conv_abc')->joinMaterial->toArray())->toBe(startMock('conv_abc')->joinMaterial->toArray())
        ->and(startMock('conv_abc')->joinMaterial->toArray())->not->toBe(startMock('conv_other')->joinMaterial->toArray());
});

it('does no network I/O in any operation', function () {
    Http::fake();

    $session = startMock();
    $this->mock->fetchTranscript($session->sessionRef, $this->auth);
    $this->mock->fetchSession($session->sessionRef, $this->auth);
    $this->mock->teardown($session->sessionRef, null, $this->auth);
    $this->mock->parseCallback(['anything' => 1]);

    Http::assertNothingSent();
});

it('reports the session as ended only once it was torn down', function () {
    $session = startMock();

    expect($this->mock->fetchSession($session->sessionRef, $this->auth)->ended)->toBeFalse();

    expect($this->mock->teardown($session->sessionRef, null, $this->auth))->toBeTrue();

    $status = $this->mock->fetchSession($session->sessionRef, $this->auth);
    expect($status->ended)->toBeTrue()->and($status->endReason)->toBe('teardown');
});

it('keeps the ended flag in the cache, which the api and the worker share', function () {
    $session = startMock();

    $this->mock->teardown($session->sessionRef, null, $this->auth);

    expect(Cache::has('hra:mock:ended:mock_conv_abc'))->toBeTrue()
        ->and((new MockProvider)->fetchSession('mock_conv_abc', $this->auth)->ended)->toBeTrue();
});

it('tears down idempotently and only the session it was asked about', function () {
    startMock('conv_one');
    startMock('conv_two');

    expect($this->mock->teardown('mock_conv_one', null, $this->auth))->toBeTrue()
        ->and($this->mock->teardown('mock_conv_one', null, $this->auth))->toBeTrue()
        ->and($this->mock->fetchSession('mock_conv_two', $this->auth)->ended)->toBeFalse();
});

it('answers a transcript of one avatar line holding the opening text', function () {
    $session = startMock('conv_abc', 'Hello there!');

    $lines = $this->mock->fetchTranscript($session->sessionRef, $this->auth);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toBeInstanceOf(TranscriptLine::class)
        ->and($lines[0]->role)->toBe(TranscriptLine::AVATAR)
        ->and($lines[0]->text)->toBe('Hello there!')
        ->and($lines[0]->occurredAt)->toBeNull();
});

it('answers an empty transcript without an opening text, and for a session it never started', function (?string $opening) {
    $session = startMock('conv_abc', $opening);

    expect($this->mock->fetchTranscript($session->sessionRef, $this->auth))->toBe([])
        ->and($this->mock->fetchTranscript('mock_never_started', $this->auth))->toBe([]);
})->with([null, '']);

it('takes no callback and needs no ingestion', function () {
    expect($this->mock->ingestion())->toBe(Ingestion::None)
        ->and($this->mock->parseCallback(['event_type' => 'system.shutdown']))->toBe([]);
});
