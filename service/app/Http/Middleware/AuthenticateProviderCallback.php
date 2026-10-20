<?php

namespace App\Http\Middleware;

use App\Accounts\AccountScope;
use App\AvatarProviders\Ingestion;
use App\AvatarProviders\ProviderRegistry;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Models\Conversation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a provider callback by the token in its path. The path must name a registered provider that
 * calls back, and the token (64 lowercase hex) must be the one minted for a conversation of that provider,
 * matched by its sha256 with `hash_equals`. Anything else is one identical 404, so the answer says nothing
 * about which part was wrong.
 *
 * This is one of the two reads that run before an account is known: it returns a row only when the token's
 * hash matches, and the account is bound from that row. The conversation is kept on the request for the controller.
 */
class AuthenticateProviderCallback
{
    public const CONVERSATION_ATTRIBUTE = 'callback_conversation';

    public function __construct(private readonly ProviderRegistry $providers) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::CONVERSATION_ATTRIBUTE, $this->conversationFor((string) $request->route('provider'), (string) $request->route('token')) ?? throw new ApiProblem(ErrorCode::NotFound));

        return $next($request);
    }

    private function conversationFor(string $provider, string $token): ?Conversation
    {
        if (! in_array($provider, $this->providers->withIngestion(Ingestion::Callback), true) || preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $conversation = Conversation::withoutGlobalScope(AccountScope::class)->where('callback_token_hash', $hash)->first();

        return $conversation !== null && hash_equals((string) $conversation->callback_token_hash, $hash) && $conversation->provider === $provider
            ? $conversation
            : null;
    }
}
