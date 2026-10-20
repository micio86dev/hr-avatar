<?php

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use stdClass;

/**
 * A JSON column that always holds an object. PHP cannot tell an empty array from an empty
 * object, so a plain `array` cast stores `{}` as `[]`; this one writes `{}` for an empty value
 * and reads back an array (empty for `{}`). Whoever shows the column to a client must render an
 * empty array as an object again.
 *
 * @implements CastsAttributes<array<string, mixed>|null, mixed>
 */
final class JsonObject implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        // The table's CHECK constraints keep these columns objects, so a decoded value is always an array.
        return (array) json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("The {$key} column takes an object, not a list or a scalar.");
        }

        return json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR);
    }
}
