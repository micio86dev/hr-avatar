<?php

namespace Tests\Support\AvatarProviders;

use App\AvatarProviders\Ingestion;
use App\AvatarProviders\SessionStatus;
use App\AvatarProviders\TranscriptLine;
use App\Credentials\ProviderAuth;
use Closure;

/**
 * An adapter whose answers a test scripts, for the jobs that call a provider (transcript, teardown,
 * session status). Register it under any id with `ScriptedProvider::register()`; it needs no
 * credential. What it was asked is recorded in `$calls`.
 */
final class ScriptedProvider extends StubProvider
{
    /** @var list<Closure(string, ProviderAuth): list<TranscriptLine>> */
    public static array $transcripts = [];

    /** @var list<bool|Closure(): bool> */
    public static array $teardowns = [];

    /** @var list<array{0: string, 1: string, 2: string|null, 3: string}> operation, session ref, context ref, auth owner */
    public static array $calls = [];

    public static function register(string $id = 'scripted'): void
    {
        self::$transcripts = [];
        self::$teardowns = [];
        self::$calls = [];
        config()->set("hr-avatar.providers.{$id}", ['adapter' => self::class, 'credential' => false]);
    }

    public function ingestion(): Ingestion
    {
        return Ingestion::None;
    }

    public function teardown(string $sessionRef, ?string $contextRef, ProviderAuth $auth): bool
    {
        self::$calls[] = ['teardown', $sessionRef, $contextRef, $auth->owner];
        $next = array_shift(self::$teardowns) ?? true;

        return $next instanceof Closure ? $next() : $next;
    }

    public function fetchTranscript(string $sessionRef, ProviderAuth $auth): array
    {
        self::$calls[] = ['fetchTranscript', $sessionRef, null, $auth->owner];
        $next = array_shift(self::$transcripts) ?? fn () => [];

        return $next($sessionRef, $auth);
    }

    public function fetchSession(string $sessionRef, ProviderAuth $auth): SessionStatus
    {
        self::$calls[] = ['fetchSession', $sessionRef, null, $auth->owner];

        return new SessionStatus(false, null);
    }
}
