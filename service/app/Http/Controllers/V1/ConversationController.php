<?php

namespace App\Http\Controllers\V1;

use App\AvatarProviders\SyncStatus;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Http\Problem;
use App\Http\Requests\CreateConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\CreatedConversationResource;
use App\Keys\ClientSecret;
use App\Models\AvatarConfig;
use App\Models\Conversation;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Conversations, from the host's back end. Creating one needs the `conversations:write` scope, reading
 * one `conversations:read`. The browser side (connect, events, end) is a separate surface with its own credentials.
 */
#[Group('Conversations')]
class ConversationController
{
    /**
     * Create a conversation
     *
     * Registers a conversation on one of the account's avatar configs and answers 201 with it, in
     * status `created`, plus a `client_secret` and its expiry. The secret is shown once: the host hands it
     * to the browser, which presents it with a publishable key to connect. Nothing is asked of the provider
     * or of any credential here; that happens when the browser connects. `external_ref` and `metadata` are
     * the host's own and come back exactly as sent. A config whose provider needs synchronizing must be
     * `synced` first (409 `config_not_synced`); a config of another account, or a deleted one, is a 404.
     */
    #[ResponseDoc(404, description: 'The avatar config does not exist, belongs to another account or was deleted (`not_found`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    #[ResponseDoc(409, description: 'The avatar config is still synchronizing, or its last synchronization failed (`config_not_synced`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    public function store(CreateConversationRequest $request, ClientSecret $secrets): JsonResponse
    {
        $conversation = DB::transaction(function () use ($request): Conversation {
            // Locked, so a delete of the same config either finishes first (this is then a 404) or waits
            // until the conversation exists (and is then refused as in use).
            $config = AvatarConfig::query()->lockForUpdate()->find($request->string('avatar_config_id')->toString())
                ?? throw new ApiProblem(ErrorCode::NotFound, 'The avatar configuration was not found.');

            if ($config->sync_status === SyncStatus::Pending || $config->sync_status === SyncStatus::Failed) {
                throw new ApiProblem(ErrorCode::ConfigNotSynced);
            }

            $conversation = new Conversation($request->values());
            $conversation->provider = $config->provider;
            $conversation->save();

            return $conversation->refresh();
        });

        $secret = $secrets->mint($conversation->id, $conversation->account_id, $conversation->created_at, $conversation->max_duration_seconds);

        return (new CreatedConversationResource($conversation, $secret))->response()->setStatusCode(201);
    }

    /**
     * Show a conversation
     *
     * One conversation of the account. Neither the client secret nor anything of the provider's (its
     * references, the callback token) is ever returned. A conversation of another account is a 404.
     */
    public function show(Conversation $conversation): ConversationResource
    {
        return new ConversationResource($conversation);
    }
}
