<?php

namespace App\Models;

use App\Accounts\BelongsToAccount;
use App\Keys\KeyMode;
use App\Keys\KeyType;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A secret or publishable key. Only the sha256 of the key and an identifying prefix are stored.
 *
 * @property string $id
 * @property string $account_id
 * @property string|null $name
 * @property KeyType $type
 * @property KeyMode $mode
 * @property string $key_prefix
 * @property string $key_hash
 * @property list<string> $scopes
 * @property list<string>|null $allowed_origins
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property string|null $rotated_from_id
 */
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use BelongsToAccount, HasFactory, HasUlids;

    /** @var list<string> */
    protected $hidden = ['key_hash'];

    protected $fillable = [
        'name', 'type', 'mode', 'key_prefix', 'key_hash', 'scopes', 'allowed_origins',
        'expires_at', 'revoked_at', 'last_used_at', 'rotated_from_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => KeyType::class,
            'mode' => KeyMode::class,
            'scopes' => 'array',
            'allowed_origins' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
