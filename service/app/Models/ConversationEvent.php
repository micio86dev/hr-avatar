<?php

namespace App\Models;

use App\Accounts\BelongsToAccount;
use App\Models\Casts\JsonObject;
use Database\Factories\ConversationEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One thing that happened in a conversation, from the browser or from the provider: an utterance
 * or a lifecycle signal. `sequence` is the service's own arrival order within the conversation.
 *
 * @property int $id
 * @property string $account_id
 * @property string $conversation_id
 * @property int $sequence
 * @property string $source
 * @property string $type
 * @property string|null $role
 * @property string|null $text
 * @property string|null $name
 * @property array<string, mixed>|null $data
 * @property Carbon|null $occurred_at
 * @property string $idempotency_key
 */
class ConversationEvent extends Model
{
    /** @use HasFactory<ConversationEventFactory> */
    use BelongsToAccount, HasFactory;

    /** Nothing is mass-assignable: the service sets the conversation, the arrival order and the source itself, member by member. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['data' => JsonObject::class, 'occurred_at' => 'datetime'];
    }
}
