<?php

namespace App\Models;

use App\Accounts\BelongsToAccount;
use App\Accounts\HasPrefixedId;
use App\Conversations\ConversationStatus;
use App\Conversations\EndedReason;
use App\Conversations\TranscriptStatus;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * One real-time avatar conversation. The host's `external_ref` and `metadata` are carried and
 * returned untouched. The provider references and the callback token hash are internals: they are
 * hidden here and no resource names them.
 *
 * @property string $id
 * @property string $account_id
 * @property string $avatar_config_id
 * @property string $provider
 * @property ConversationStatus $status
 * @property string $language
 * @property string $system_prompt
 * @property string|null $opening_text
 * @property int $max_duration_seconds
 * @property string|null $external_ref
 * @property stdClass $metadata
 * @property string|null $provider_session_ref
 * @property string|null $provider_context_ref
 * @property string|null $callback_token_hash
 * @property EndedReason|null $ended_reason
 * @property string|null $provider_end_reason
 * @property TranscriptStatus $transcript_status
 * @property Carbon|null $connecting_at
 * @property Carbon|null $live_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $released_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AvatarConfig $avatarConfig
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use BelongsToAccount, HasFactory, HasPrefixedId;

    /**
     * The column defaults, so a model that was just created holds what the table holds before it is read back.
     *
     * @var array<string, string>
     */
    protected $attributes = ['status' => 'created', 'transcript_status' => 'pending', 'metadata' => '{}'];

    /** The members the host chooses. The provider (the config's), the state, the provider references and the owner are written by the service. */
    protected $fillable = ['avatar_config_id', 'language', 'system_prompt', 'opening_text', 'max_duration_seconds', 'external_ref', 'metadata'];

    protected $hidden = ['provider_session_ref', 'provider_context_ref', 'callback_token_hash'];

    protected static function idPrefix(): string
    {
        return 'conv_';
    }

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'ended_reason' => EndedReason::class,
            'transcript_status' => TranscriptStatus::class,
            // An object, not an array: PHP cannot tell `{}` from `[]`, and the host's `{}` must stay `{}`.
            'metadata' => 'object',
            'connecting_at' => 'datetime',
            'live_at' => 'datetime',
            'ended_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AvatarConfig, $this>
     */
    public function avatarConfig(): BelongsTo
    {
        return $this->belongsTo(AvatarConfig::class)->withTrashed();
    }
}
