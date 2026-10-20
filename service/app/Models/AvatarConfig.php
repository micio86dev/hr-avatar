<?php

namespace App\Models;

use App\Accounts\BelongsToAccount;
use App\Accounts\HasPrefixedId;
use App\AvatarProviders\SyncStatus;
use App\Models\Casts\JsonObject;
use Database\Factories\AvatarConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A reusable avatar setup: the provider, its provider-specific settings, an optional persona,
 * language-model and voice binding, and the state of its provider-side objects. Deleting is
 * soft, so conversations that used it keep their reference.
 *
 * @property string $id
 * @property string $account_id
 * @property string $name
 * @property string $provider
 * @property array<string, mixed> $config
 * @property array<string, mixed>|null $persona
 * @property string|null $llm_model_id
 * @property array<string, mixed>|null $voice
 * @property string|null $external_ref
 * @property SyncStatus $sync_status
 * @property string|null $sync_error
 * @property Carbon|null $synced_at
 * @property int $sync_revision
 * @property array<string, mixed> $provider_state
 * @property Carbon|null $deleted_at
 * @property-read LlmModel|null $llmModel
 */
class AvatarConfig extends Model
{
    /** @use HasFactory<AvatarConfigFactory> */
    use BelongsToAccount, HasFactory, HasPrefixedId, SoftDeletes;

    /**
     * The column defaults, so a model that was just created holds what the table holds (an object,
     * never null) before it is read back.
     *
     * @var array<string, int|string>
     */
    protected $attributes = ['config' => '{}', 'provider_state' => '{}', 'sync_revision' => 0, 'sync_status' => 'not_required'];

    /** The members a client may set. The sync state, its revision and the adapter's own state are written with forceFill. */
    protected $fillable = ['name', 'provider', 'config', 'persona', 'llm_model_id', 'voice', 'external_ref'];

    protected static function idPrefix(): string
    {
        return 'avc_';
    }

    protected function casts(): array
    {
        return [
            'config' => JsonObject::class,
            'persona' => JsonObject::class,
            'voice' => JsonObject::class,
            'provider_state' => JsonObject::class,
            'sync_status' => SyncStatus::class,
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LlmModel, $this>
     */
    public function llmModel(): BelongsTo
    {
        return $this->belongsTo(LlmModel::class, 'llm_model_id');
    }
}
