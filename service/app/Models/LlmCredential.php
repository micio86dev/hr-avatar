<?php

namespace App\Models;

use App\Accounts\BelongsToAccount;
use Database\Factories\LlmCredentialFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An account's own key for a language-model provider of the catalogue. There is no platform
 * fallback. Encrypted at rest, hidden from serialization, never part of a response.
 *
 * @property string $id
 * @property string $account_id
 * @property string $llm_provider
 * @property string $api_key
 * @property string|null $last_four
 * @property Carbon|null $updated_at
 */
class LlmCredential extends Model
{
    /** @use HasFactory<LlmCredentialFactory> */
    use BelongsToAccount, HasFactory, HasUlids;

    /** @var list<string> */
    protected $hidden = ['api_key'];

    protected $fillable = ['llm_provider', 'api_key', 'last_four'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }
}
