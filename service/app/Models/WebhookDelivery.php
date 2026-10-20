<?php

namespace App\Models;

use App\Accounts\BelongsToAccount;
use App\Webhooks\Outbound\DeliveryStatus;
use App\Webhooks\Outbound\EventType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One event on its way to one endpoint, with what happened to each attempt. The `payload` is the exact
 * signed body: it holds the host's data, so it is hidden and no endpoint of the API returns it. Rows are
 * written by the dispatcher and the delivery job only.
 *
 * @property string $id
 * @property string $account_id
 * @property string $webhook_endpoint_id
 * @property string $conversation_id
 * @property string $event_id
 * @property EventType $event_type
 * @property string $payload
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $last_attempt_at
 * @property int|null $last_response_status
 * @property string|null $last_error
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 */
class WebhookDelivery extends Model
{
    use BelongsToAccount, HasUlids;

    protected $guarded = ['*'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'event_type' => EventType::class,
            'status' => DeliveryStatus::class,
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
