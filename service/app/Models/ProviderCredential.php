<?php

namespace App\Models;

use App\Accounts\BelongsToAccount;
use Database\Factories\ProviderCredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An account's own key for an avatar or voice provider. Write-only: the key is encrypted at
 * rest (the cast), hidden from serialization ($hidden) and never part of a response.
 *
 * @property string $id
 * @property string $account_id
 * @property string $provider
 * @property string $api_key
 * @property string|null $last_four
 * @property Carbon|null $updated_at
 */
class ProviderCredential extends Model
{
    /** @use HasFactory<ProviderCredentialFactory> */
    use BelongsToAccount, HasFactory, HasUlids;

    /** @var list<string> */
    protected $hidden = ['api_key'];

    protected $fillable = ['provider', 'api_key', 'last_four'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }
}
