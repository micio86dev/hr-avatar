<?php

namespace Tests\Support\AvatarProviders;

use App\AvatarProviders\ConversationSpec;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\JoinMaterial;
use App\AvatarProviders\ProviderSession;
use App\AvatarProviders\SessionStatus;
use App\AvatarProviders\TranscriptLine;
use App\Credentials\ProviderAuth;
use App\Models\AvatarConfig;
use Closure;

/**
 * An adapter whose answers a test scripts, for the jobs that call a provider (transcript, teardown,
 * session status, start). Register it under any id with `ScriptedProvider::register()`; it needs no
 * credential. What it was asked is recorded in `$calls`. Its ingestion mode is `$ingestion` (none by default).
 */
final class ScriptedProvider extends StubProvider
{
    /** @var list<Closure(string, ProviderAuth): list<TranscriptLine>> */
    public static array $transcripts = [];

    /** @var list<bool|Closure(): bool> */
    public static array $teardowns = [];

    /** @var list<Closure(string, ConversationSpec, AvatarConfig, ProviderAuth, ?string): ProviderSession|null> */
    public static array $starts = [];

    /** @var list<array{0: string, 1: string, 2: string|null, 3: string}> operation, session ref (conversation id for a start), context ref (callback url for a start), auth owner */
    public static array $calls = [];

    public static Ingestion $ingestion = Ingestion::None;

    /** The last spec a start was given. */
    public static ?ConversationSpec $lastSpec = null;

    public static function register(string $id = 'scripted'): void
    {
        self::$transcripts = [];
        self::$teardowns = [];
        self::$starts = [];
        self::$calls = [];
        self::$ingestion = Ingestion::None;
        self::$lastSpec = null;
        config()->set("hr-avatar.providers.{$id}", ['adapter' => self::class, 'credential' => false]);
    }

    public function ingestion(): Ingestion
    {
        return self::$ingestion;
    }

    public function start(string $conversationId, ConversationSpec $spec, AvatarConfig $config, ProviderAuth $auth, ?string $callbackUrl): ProviderSession
    {
        self::$calls[] = ['start', $conversationId, $callbackUrl, $auth->owner];
        self::$lastSpec = $spec;
        $next = array_shift(self::$starts);

        return ($next === null ? null : $next($conversationId, $spec, $config, $auth, $callbackUrl))
            ?? new ProviderSession("scripted_{$conversationId}", "context_{$conversationId}", new JoinMaterial('scripted', 'mock', "mock://scripted/{$conversationId}", 'join-token', now()->addHour()->toDateTimeImmutable()));
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
