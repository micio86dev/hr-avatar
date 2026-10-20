<?php

use App\Accounts\CurrentAccount;
use App\Conversations\ConversationStatus;
use App\Keys\ClientSecret;
use App\Keys\KeyGenerator;
use App\Keys\KeyMode;
use App\Keys\KeyType;
use App\Models\Account;
use App\Models\ApiKey;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use App\Models\ProviderWebhookReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Feature and Unit tests boot the application against the PostgreSQL test database
 * (json, jsonb and CHECK constraints need a real engine). Every outbound HTTP call
 * must be faked: a stray request is a test failure, never a network call.
 * Architecture tests boot the application but need no database.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature', 'Unit');

pest()->extend(TestCase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Arch');

/*
 * The members of a conversation resource, in order. Defined here (not in the first test file that used it)
 * so a file run on its own, or on another worker of the parallel runner, finds it.
 */
const CONVERSATION_MEMBERS = ['id', 'status', 'avatar_config_id', 'provider', 'language', 'max_duration_seconds', 'external_ref', 'metadata', 'ended_reason', 'transcript_status', 'created_at', 'connecting_at', 'live_at', 'ended_at'];

/**
 * Runs a callback with an account bound, as the authentication middleware would for a request.
 */
function inAccount(Account $account, Closure $callback): mixed
{
    return CurrentAccount::runAs($account->id, $callback);
}

/**
 * Creates a key for an account and returns it with its raw value, which only exists here.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: ApiKey, 1: string}
 */
function issueKey(
    Account $account,
    array $attributes = [],
    KeyType $type = KeyType::Secret,
    KeyMode $mode = KeyMode::Live,
): array {
    $raw = KeyGenerator::generate($type, $mode);

    return [inAccount($account, fn () => ApiKey::factory()->withRawKey($raw)->create($attributes)), $raw];
}

/**
 * The client secret a browser would hold for a conversation, as the create endpoint would have minted it.
 */
function clientSecretFor(Conversation $conversation): string
{
    return app(ClientSecret::class)->mint($conversation->id, $conversation->account_id, $conversation->created_at, $conversation->max_duration_seconds)->token;
}

/**
 * The headers of a browser call: a publishable key, a client secret and an allowed origin.
 *
 * @return array<string, string>
 */
function browserHeaders(string $publishableKey, string $clientSecret, string $origin = 'https://app.example.com'): array
{
    return ['X-HRA-Publishable-Key' => $publishableKey, 'Authorization' => "Bearer {$clientSecret}", 'Origin' => $origin];
}

/**
 * Records everything the application logs from now on, message, context and (for a logged
 * exception) its message and stack trace as text, so a test can assert a secret is nowhere in it.
 *
 * @return ArrayObject<int, string>
 */
function captureLogs(): ArrayObject
{
    $logged = new ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($logged) {
        $exception = $event->context['exception'] ?? null;
        $context = array_diff_key($event->context, ['exception' => true]);

        // json_encode returns false for a context it cannot encode (invalid UTF-8, say), and concatenating false drops
        // the whole context: a "secret is absent" assertion would then pass without having read that line.
        $encoded = json_encode($context);

        $logged->append($event->message.' '.($encoded === false ? print_r($context, true) : $encoded).($exception instanceof Throwable ? ' '.$exception->getMessage().' '.$exception->getTraceAsString() : ''));
    });

    return $logged;
}

/**
 * A connecting Tavus conversation whose callback token (plain) is `$token`; only its hash is stored.
 */
function callbackConversation(Account $account, string $token, string $sessionRef = 'c9f2a1c7e4b3d4a1'): Conversation
{
    $config = inAccount($account, fn () => AvatarConfig::factory()->create(['provider' => 'tavus', 'config' => ['replica_id' => 'r_1', 'persona_id' => 'p_1']]));

    return inAccount($account, fn () => Conversation::factory()->create([
        'avatar_config_id' => $config->id, 'provider' => 'tavus', 'status' => ConversationStatus::Connecting,
        'connecting_at' => now()->subMinute(), 'provider_session_ref' => $sessionRef, 'callback_token_hash' => hash('sha256', $token),
    ]));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function inboundCallback(array $overrides = []): array
{
    return [...json_decode((string) file_get_contents(__DIR__.'/Fixtures/Tavus/callback_shutdown.json'), true, flags: JSON_THROW_ON_ERROR), ...$overrides];
}

/** @param array<string, mixed>|string $body */
function postInbound(string $provider, string $token, array|string $body, array $headers = []): TestResponse
{
    $content = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

    return test()->call('POST', "/v1/webhooks/providers/{$provider}/{$token}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...$headers], $content);
}

/**
 * How many provider receipts exist in all accounts together. Read one account at a time, each under its own account,
 * like the sweeper: the total is global, no read removes the account scope.
 */
function inboundReceiptCount(): int
{
    return Account::query()->pluck('id')->sum(fn (string $id): int => CurrentAccount::runAs($id, fn (): int => ProviderWebhookReceipt::count()));
}
