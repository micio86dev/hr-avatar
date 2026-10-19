<?php

namespace App\Http\Middleware;

use App\Accounts\CurrentAccount;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Keys\ClientSecret;
use App\Keys\FailedAuthLimiter;
use App\Keys\KeyResolver;
use App\Keys\KeyType;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a browser request. A publishable key alone grants nothing: the request also needs
 * an allowed `Origin` and the per-conversation client secret, and that secret must be for the
 * conversation in the URL and for the key's own account. Every 401 counts against the caller's
 * failed-auth budget; a 403 does not.
 */
class AuthenticateClient
{
    public const PUBLISHABLE_HEADER = 'X-HRA-Publishable-Key';

    /** Set to the request's origin once the allowlist has passed; ClientCors reads it. */
    public const ORIGIN_ATTRIBUTE = 'client_origin';

    public function __construct(
        private readonly KeyResolver $resolver,
        private readonly ClientSecret $secrets,
        private readonly FailedAuthLimiter $failures,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->failures->ensureAllowed($request);

        try {
            $key = $this->authenticate($request);
        } catch (ApiProblem $problem) {
            if ($problem->errorCode->status() === 401) {
                $this->failures->recordFailure($request);
            }

            throw $problem;
        }

        $request->attributes->set('api_key_id', $key->id);
        $request->attributes->set('key_mode', $key->mode->value);

        return CurrentAccount::runAs($key->account_id, fn () => $next($request));
    }

    /**
     * @throws ApiProblem
     */
    private function authenticate(Request $request): ApiKey
    {
        $key = $this->resolver->resolve((string) $request->header(self::PUBLISHABLE_HEADER, ''), KeyType::Publishable);

        $origin = $request->headers->get('Origin');
        if (! is_string($origin) || ! in_array($origin, $key->allowed_origins ?? [], true)) {
            throw new ApiProblem(ErrorCode::OriginNotAllowed);
        }
        $request->attributes->set(self::ORIGIN_ATTRIBUTE, $origin);

        $bearer = $request->bearerToken();
        if ($bearer === null || $bearer === '') {
            throw new ApiProblem(ErrorCode::Unauthenticated);
        }

        $secret = $this->secrets->parse($bearer) ?? throw new ApiProblem(ErrorCode::ClientSecretInvalid);
        if ($secret->isExpired()) {
            throw new ApiProblem(ErrorCode::ClientSecretExpired);
        }

        $conversation = $request->route('conversation');
        if ($secret->conversationId !== $conversation || $secret->accountId !== $key->account_id) {
            throw new ApiProblem(ErrorCode::Forbidden);
        }

        return $key;
    }
}
