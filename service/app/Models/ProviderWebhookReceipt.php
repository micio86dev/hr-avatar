<?php

namespace App\Models;

use App\Accounts\BelongsToAccount;
use App\AvatarProviders\ProviderEventKind;
use App\Models\Casts\JsonObject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One provider event, stored before anything is done with it: a callback that was authenticated, or the
 * observation of a poll. The `payload` is what the provider sent and may hold a transcript, so it is hidden
 * and no endpoint of the API returns it. Rows are written by the ingestor and marked processed by the
 * processing job, both by query.
 *
 * @property string $id
 * @property string $account_id
 * @property string $conversation_id
 * @property string $provider
 * @property string $provider_ref
 * @property string $idempotency_key
 * @property ProviderEventKind $kind
 * @property Carbon $occurred_at
 * @property string|null $provider_end_reason
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 */
class ProviderWebhookReceipt extends Model
{
    use BelongsToAccount, HasUlids;

    protected $guarded = ['*'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'kind' => ProviderEventKind::class,
            'payload' => JsonObject::class,
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
