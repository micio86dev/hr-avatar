<?php

namespace App\Models;

use App\Accounts\BelongsToAccount;
use App\Accounts\HasPrefixedId;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A URL of the host that receives signed events. The signing secret is encrypted at rest, hidden from
 * serialization and shown once, by the create response.
 *
 * @property string $id
 * @property string $account_id
 * @property string $url
 * @property list<string> $events
 * @property bool $enabled
 * @property string|null $description
 * @property string $secret
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use BelongsToAccount, HasFactory, HasPrefixedId;

    /** The members the host chooses. The owner, the id and the secret are the service's. */
    protected $fillable = ['url', 'events', 'enabled', 'description'];

    protected $hidden = ['secret'];

    protected static function idPrefix(): string
    {
        return 'we_';
    }

    /**
     * A new signing secret: `hra_whsec_` and 64 hex characters.
     */
    public static function newSecret(): string
    {
        return 'hra_whsec_'.bin2hex(random_bytes(32));
    }

    protected function casts(): array
    {
        return ['events' => 'array', 'enabled' => 'boolean', 'secret' => 'encrypted'];
    }
}
