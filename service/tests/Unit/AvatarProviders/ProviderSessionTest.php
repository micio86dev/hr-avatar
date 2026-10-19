<?php

use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\JoinMaterial;
use App\AvatarProviders\ProviderEvent;
use App\AvatarProviders\ProviderEventKind;
use App\AvatarProviders\ProviderSession;
use App\AvatarProviders\SessionStatus;
use App\AvatarProviders\TranscriptLine;
use Illuminate\Support\Carbon;

const SESSION_TOKEN = 'tok_ephemeral_session_value';

function joinMaterial(array $overrides = []): JoinMaterial
{
    return new JoinMaterial(...[
        'provider' => 'mock',
        'transport' => 'mock',
        'conversationUrl' => 'mock://conversation/conv_1',
        'sessionToken' => SESSION_TOKEN,
        'expiresAt' => new DateTimeImmutable('2026-10-19 13:00:00 UTC'),
        ...$overrides,
    ]);
}

describe('JoinMaterial', function () {
    it('holds exactly the five members of the contract', function () {
        $join = joinMaterial();

        expect($join->provider)->toBe('mock')
            ->and($join->transport)->toBe('mock')
            ->and($join->conversationUrl)->toBe('mock://conversation/conv_1')
            ->and($join->sessionToken)->toBe(SESSION_TOKEN)
            ->and($join->expiresAt->format('c'))->toBe('2026-10-19T13:00:00+00:00')
            ->and(array_keys(get_object_vars($join)))->toBe(['provider', 'transport', 'conversationUrl', 'sessionToken', 'expiresAt']);
    });

    it('allows either the URL or the token to be absent', function () {
        expect(joinMaterial(['conversationUrl' => null])->conversationUrl)->toBeNull()
            ->and(joinMaterial(['sessionToken' => null])->toArray()['session_token'])->toBeNull();
    });

    it('serializes with the snake_case keys the SDK reads, and the expiry as a Zulu timestamp', function () {
        expect(joinMaterial()->toArray())->toBe([
            'provider' => 'mock',
            'transport' => 'mock',
            'conversation_url' => 'mock://conversation/conv_1',
            'session_token' => SESSION_TOKEN,
            'expires_at' => '2026-10-19T13:00:00Z',
        ]);
    });

    it('matches the members of the SDK\'s JoinMaterial type', function () {
        $sdk = (string) file_get_contents(dirname(base_path()).'/sdk/src/api.ts');
        preg_match('/export interface JoinMaterial \{(.*?)\n\}/s', $sdk, $block);
        preg_match_all('/^\s*(\w+)\??:/m', $block[1] ?? '', $members);

        expect($members[1])->not->toBeEmpty()->and(array_keys(joinMaterial()->toArray()))->toEqualCanonicalizing($members[1]);
    });

    it('refuses an empty provider or transport, which would route a teardown or a browser nowhere', function (string $member) {
        expect(fn () => joinMaterial([$member => '']))->toThrow(InvalidArgumentException::class);
    })->with(['provider', 'transport']);

    it('is immutable', function () {
        $join = joinMaterial();

        expect(fn () => $join->provider = 'other')->toThrow(Error::class);
    });

    it('keeps the session token out of dumps', function () {
        ob_start();
        var_dump(joinMaterial());
        print_r(joinMaterial());
        $dumped = ob_get_clean();

        expect($dumped)->not->toContain(SESSION_TOKEN)->toContain('[redacted]')->toContain('mock://conversation/conv_1');
    });
});

describe('ProviderSession', function () {
    it('pairs the provider\'s refs with the browser-safe join material', function () {
        $session = new ProviderSession('mock_conv_1', 'ctx_1', joinMaterial());

        expect($session->sessionRef)->toBe('mock_conv_1')
            ->and($session->contextRef)->toBe('ctx_1')
            ->and($session->joinMaterial->provider)->toBe('mock');
    });

    it('has no context by default', function () {
        expect((new ProviderSession('mock_conv_1', null, joinMaterial()))->contextRef)->toBeNull();
    });

    it('refuses an empty session ref, which could never be torn down', function () {
        expect(fn () => new ProviderSession('', null, joinMaterial()))->toThrow(InvalidArgumentException::class);
    });
});

describe('ConversationSpec', function () {
    it('is exactly the five members the contract names', function () {
        $spec = new ConversationSpec('You are helpful.', 'Hello!', 'it', 600, (object) ['k' => 'v']);

        expect(array_keys(get_object_vars($spec)))->toBe(['systemPrompt', 'openingText', 'language', 'maxDurationSeconds', 'metadata'])
            ->and($spec->metadata)->toBeInstanceOf(stdClass::class)
            ->and((new ConversationSpec('p', null, 'en', 30, new stdClass))->openingText)->toBeNull();
    });
});

describe('SessionStatus', function () {
    it('says whether the provider session ended and why', function () {
        expect((new SessionStatus(false, null))->ended)->toBeFalse()
            ->and((new SessionStatus(true, 'IDLE_TIMEOUT'))->endReason)->toBe('IDLE_TIMEOUT');
    });
});

describe('TranscriptLine', function () {
    it('takes a user or an avatar line', function (string $role) {
        $line = new TranscriptLine($role, 'Hello', Carbon::parse('2026-10-19 12:00:00'), 1.5);

        expect($line->role)->toBe($role)->and($line->text)->toBe('Hello')->and($line->offsetSeconds)->toBe(1.5);
    })->with([TranscriptLine::USER, TranscriptLine::AVATAR]);

    it('refuses any other role instead of coercing it', function (string $role) {
        expect(fn () => new TranscriptLine($role, 'Hi', null, null))->toThrow(InvalidArgumentException::class);
    })->with(['moderator', 'system', '', 'User']);

    it('may have no timing at all', function () {
        $line = new TranscriptLine(TranscriptLine::AVATAR, 'Hi', null, null);

        expect($line->occurredAt)->toBeNull()->and($line->offsetSeconds)->toBeNull();
    });
});

describe('ProviderEvent and Ingestion', function () {
    it('normalizes what a provider reports into one shape', function () {
        $event = new ProviderEvent('tavus', 'c123', ProviderEventKind::Ended, new DateTimeImmutable('2026-10-19 12:00:00'), 'key-1', 'shutdown', ['a' => 1]);

        expect($event->kind)->toBe(ProviderEventKind::Ended)
            ->and($event->providerEndReason)->toBe('shutdown')
            ->and($event->payload)->toBe(['a' => 1]);
    });

    it('knows the four kinds of event and the three ways events arrive', function () {
        expect(array_map(fn (ProviderEventKind $kind) => $kind->value, ProviderEventKind::cases()))->toBe(['joined', 'ended', 'transcript_ready', 'ignored'])
            ->and(array_map(fn (Ingestion $mode) => $mode->value, Ingestion::cases()))->toBe(['callback', 'poll', 'none']);
    });
});
