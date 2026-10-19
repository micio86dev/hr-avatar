<?php

namespace App\Models;

use Database\Factories\LlmModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An entry of the platform language-model catalogue. Not account-scoped: the platform owns it
 * (`LlmModelSeeder`), accounts read the enabled entries and the API offers no way to change them.
 *
 * @property string $id
 * @property string $provider
 * @property string $model
 * @property string $display_name
 * @property string $base_url
 * @property bool $enabled
 */
class LlmModel extends Model
{
    /** @use HasFactory<LlmModelFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'provider', 'model', 'display_name', 'base_url', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
