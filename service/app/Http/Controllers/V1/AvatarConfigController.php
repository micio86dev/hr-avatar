<?php

namespace App\Http\Controllers\V1;

use App\AvatarProviders\ProviderRegistry;
use App\AvatarProviders\SyncStatus;
use App\Conversations\AvatarConfigSync;
use App\Http\ApiProblem;
use App\Http\ErrorCode;
use App\Http\Problem;
use App\Http\Requests\CreateAvatarConfigRequest;
use App\Http\Requests\ListAvatarConfigsRequest;
use App\Http\Requests\UpdateAvatarConfigRequest;
use App\Http\Resources\AvatarConfigResource;
use App\Http\Resources\PagedCollection;
use App\Models\AvatarConfig;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Reusable avatar configurations: a provider, its settings, an optional persona, language model and
 * voice, and the state of the provider-side objects they need. Reads need the `avatar_configs:read`
 * scope, writes `avatar_configs:write`.
 */
#[Group('Avatar configs')]
class AvatarConfigController
{
    /** The members the provider-side objects are built from: a real change to one of them needs a new sync. */
    private const SYNCED_MEMBERS = ['config', 'persona', 'llm_model_id', 'voice'];

    public function __construct(private readonly ProviderRegistry $providers) {}

    /**
     * List avatar configs
     *
     * The account's configs, newest first. `external_ref` keeps only those carrying exactly that
     * reference (case-sensitive, no wildcards); it is applied before the page is cut, so a host that
     * shares one account across its own tenants never loses a config to the page size.
     */
    public function index(ListAvatarConfigsRequest $request): PagedCollection
    {
        $query = AvatarConfig::query();

        if ($request->externalRef() !== null) {
            $query->where('external_ref', $request->externalRef());
        }

        return new PagedCollection($request->page($query), AvatarConfigResource::class);
    }

    /**
     * Create an avatar config
     *
     * `config` is checked against the settings the provider's adapter declares (`GET /v1/catalogue`):
     * an unknown key, a missing required key or a wrong value is a 422 naming `config.<key>`.
     * `llm_model_id` must be an enabled catalogue model; `voice` is `{engine, voice_id}` and the
     * engine must be one the provider speaks with. A config whose provider needs objects created for
     * it starts `pending`, any other `not_required`.
     */
    public function store(CreateAvatarConfigRequest $request): JsonResponse
    {
        $config = new AvatarConfig($request->values());
        $config->sync_status = $this->syncStatusFor($config);
        $config->save();
        AvatarConfigSync::dispatchWhenPending($config);

        return (new AvatarConfigResource($config->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Show an avatar config
     *
     * One config of the account. A config of another account, or a deleted one, is a 404.
     */
    public function show(AvatarConfig $avatarConfig): AvatarConfigResource
    {
        return new AvatarConfigResource($avatarConfig);
    }

    /**
     * Update an avatar config
     *
     * Each member sent replaces the stored one as a whole; members left out are kept. The provider
     * cannot change. Changing the `config`, `persona`, `llm_model_id` or `voice` to something
     * different moves the config back to `pending` (or `not_required` when its provider needs
     * nothing for it); a change to the name or the reference leaves the sync state alone.
     */
    public function update(UpdateAvatarConfigRequest $request, AvatarConfig $avatarConfig): AvatarConfigResource
    {
        $avatarConfig->fill($request->changes());
        $changed = array_any(self::SYNCED_MEMBERS, fn (string $member) => self::differs($avatarConfig, $member));

        if ($changed) {
            // A new revision overtakes any run still in flight for the older state, whatever this change
            // settles as. The increment is done by the database, so a concurrent change cannot share it.
            $avatarConfig->forceFill(['sync_revision' => self::nextRevision()]);
            $avatarConfig->sync_status = $this->syncStatusFor($avatarConfig);
            $avatarConfig->sync_error = null;
        }

        $avatarConfig->save();
        $avatarConfig->refresh();

        if ($changed) {
            AvatarConfigSync::dispatchWhenPending($avatarConfig);
        }

        return new AvatarConfigResource($avatarConfig);
    }

    /**
     * Synchronize an avatar config
     *
     * Queues a sync of the provider-side objects the config needs (for example a persona, a
     * language-model configuration or a bound voice) and answers 202 with the config, now `pending`.
     * Use it to retry a `failed` config or to push again a `synced` one. A config whose provider
     * needs no such objects is refused with 409 `invalid_state`.
     */
    #[ResponseDoc(409, description: 'The config needs no provider-side objects (`invalid_state`).', mediaType: Problem::CONTENT_TYPE, type: Problem::SHAPE)]
    public function sync(AvatarConfig $avatarConfig): JsonResponse
    {
        if (! $this->providers->get($avatarConfig->provider)->syncRequired($avatarConfig)) {
            throw new ApiProblem(ErrorCode::InvalidState, 'This avatar configuration needs no synchronization.');
        }

        $avatarConfig->forceFill(['sync_status' => SyncStatus::Pending, 'sync_error' => null, 'sync_revision' => self::nextRevision()])->save();
        $avatarConfig->refresh();
        AvatarConfigSync::dispatchWhenPending($avatarConfig);

        return (new AvatarConfigResource($avatarConfig))->response()->setStatusCode(202);
    }

    /**
     * Delete an avatar config
     *
     * A soft delete: the config leaves the list and cannot start new conversations, and the
     * conversations that used it keep their reference to it.
     */
    public function destroy(AvatarConfig $avatarConfig): Response
    {
        $avatarConfig->delete();

        return response()->noContent();
    }

    /**
     * Whether the member now holds something other than it did. Compared as data, not as stored
     * text: jsonb reorders the keys of an object, which must not look like a change.
     */
    private static function differs(AvatarConfig $config, string $member): bool
    {
        return self::canonical($config->getOriginal($member)) !== self::canonical($config->getAttribute($member));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * The revision after this write, computed by the database from the row's own value. The model is
     * read again after the save, so the job carries the revision that was really stored.
     */
    private static function nextRevision(): Expression
    {
        return DB::raw('sync_revision + 1');
    }

    private function syncStatusFor(AvatarConfig $config): SyncStatus
    {
        return $this->providers->get($config->provider)->syncRequired($config) ? SyncStatus::Pending : SyncStatus::NotRequired;
    }
}
