<?php

namespace App\Http\Controllers\V1;

use App\Accounts\CurrentAccount;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Http\Problem;
use App\Http\Requests\CreateApiKeyRequest;
use App\Http\Requests\PaginationRequest;
use App\Http\Requests\RotateApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Http\Resources\IssuedApiKeyResource;
use App\Http\Resources\PagedCollection;
use App\Keys\KeyIssuer;
use App\Keys\KeyMode;
use App\Keys\Scope;
use App\Models\ApiKey;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Key management for the authenticated account. Requires the `api_keys:manage` scope.
 */
#[Group('API keys')]
class ApiKeyController
{
    /**
     * List API keys
     *
     * The account's keys, newest first. Neither the key nor its hash is ever returned after creation.
     */
    public function index(PaginationRequest $request): PagedCollection
    {
        return new PagedCollection($request->page(ApiKey::query()), ApiKeyResource::class);
    }

    /**
     * Create an API key
     *
     * The response carries the full `key` once; it cannot be retrieved afterwards. A secret key
     * needs an explicit scope list, a publishable key needs 1 to 20 allowed origins. A key can only
     * issue keys of its own mode and, unless it holds `*`, only scopes it holds itself.
     */
    #[Response(403, description: 'The caller lacks a requested scope (`insufficient_scope`) or asked for the other mode (`forbidden`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    public function store(CreateApiKeyRequest $request, KeyIssuer $issuer): JsonResponse
    {
        $scopes = $request->scopeList();
        $this->ensureWithinCaller($request, $request->mode(), $scopes);

        [$key, $raw] = $issuer->issue(
            app(CurrentAccount::class)->require(),
            $request->type(),
            $request->mode(),
            $scopes,
            $request->origins(),
            $request->expiresAt(),
        );

        return (new IssuedApiKeyResource($key, $raw))->response()->setStatusCode(201);
    }

    /**
     * Revoke an API key
     *
     * Takes effect at once. Revoking a key that is already revoked changes nothing and keeps the
     * original `revoked_at`. Revoking only removes access, so unlike create and rotate it needs
     * no scope or mode comparison with the caller: any key with `api_keys:manage` may revoke any
     * key of the account, itself included. The way back from a lockout is the `hra:issue-key` command.
     */
    public function revoke(ApiKey $apiKey): ApiKeyResource
    {
        ApiKey::query()->whereKey($apiKey->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return new ApiKeyResource($apiKey->refresh());
    }

    /**
     * Rotate an API key
     *
     * Issues a successor with the same type, mode, scopes and origins, returned once as `key`. The
     * successor does not inherit an expiry: it has none until one is set by creating a key instead.
     * The old key keeps working for `overlap_seconds` (default 3600, 0 revokes it immediately) and never
     * outlives an expiry it already had. A revoked or expired key cannot be rotated.
     */
    #[Response(403, description: 'The key carries a scope the caller lacks (`insufficient_scope`) or is of the other mode (`forbidden`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    #[Response(409, description: 'The key is revoked or expired (`invalid_state`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    public function rotate(RotateApiKeyRequest $request, ApiKey $apiKey, KeyIssuer $issuer): JsonResponse
    {
        $this->ensureWithinCaller($request, $apiKey->mode, $apiKey->scopes);

        [$successor, $raw] = DB::transaction(function () use ($request, $apiKey, $issuer) {
            // Locked so that two concurrent rotations cannot both succeed.
            $old = ApiKey::query()->lockForUpdate()->findOrFail($apiKey->id);

            if ($old->revoked_at !== null || $old->expires_at?->isPast()) {
                throw new ApiProblem(ErrorCode::InvalidState, 'A revoked or expired key cannot be rotated.');
            }

            $issued = $issuer->issue($old->account_id, $old->type, $old->mode, $old->scopes, $old->allowed_origins, rotatedFromId: $old->id);

            $overlap = $request->overlapSeconds();
            if ($overlap === 0) {
                $old->revoked_at = now();
            } else {
                $until = now()->addSeconds($overlap);
                $old->expires_at = $old->expires_at !== null && $old->expires_at->lt($until) ? $old->expires_at : $until;
            }
            $old->save();

            return $issued;
        });

        return (new IssuedApiKeyResource($successor, $raw))->response()->setStatusCode(201);
    }

    /**
     * A key cannot hand out more than it holds: the other mode is forbidden, and so is any scope
     * the caller lacks (the wildcard holder may grant anything).
     *
     * @param  list<string>  $scopes
     */
    private function ensureWithinCaller(Request $request, KeyMode $mode, array $scopes): void
    {
        if ($request->attributes->get('key_mode') !== $mode->value) {
            throw new ApiProblem(ErrorCode::Forbidden, 'A key can only issue keys of its own mode.');
        }

        $granted = $request->attributes->get('key_scopes');
        $granted = is_array($granted) ? array_values(array_filter($granted, is_string(...))) : [];

        foreach ($scopes as $scope) {
            if (! Scope::grantsNamed($granted, $scope)) {
                throw new ApiProblem(ErrorCode::InsufficientScope, "The calling key does not hold the scope \"{$scope}\".");
            }
        }
    }
}
