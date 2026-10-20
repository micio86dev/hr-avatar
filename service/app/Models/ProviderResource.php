<?php

namespace App\Models;

use App\Accounts\BelongsToAccount;
use App\AvatarProviders\ResourceKind;
use Database\Factories\ProviderResourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One object this service created inside a provider (a stored secret, a bound voice), found
 * again by its natural key instead of being created twice. Keyed by the provider key's
 * fingerprint, so switching keys never reuses another key's objects.
 *
 * @property string $id
 * @property string $account_id
 * @property string $provider
 * @property string $key_fingerprint
 * @property ResourceKind $kind
 * @property string $natural_key
 * @property string $external_id
 */
class ProviderResource extends Model
{
    /** @use HasFactory<ProviderResourceFactory> */
    use BelongsToAccount, HasFactory, HasUlids;

    protected $fillable = ['provider', 'key_fingerprint', 'kind', 'natural_key', 'external_id'];

    protected function casts(): array
    {
        return ['kind' => ResourceKind::class];
    }
}
