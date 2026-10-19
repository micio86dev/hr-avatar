<?php

namespace App\Accounts;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A string key made of the model's prefix and a lowercase ULID (`avc_01j...`): readable in logs
 * and in a host's own records, and sortable by creation time. The column is `string(31)`, which
 * fits the longest prefix in use (`conv_`).
 *
 * @mixin Model
 */
trait HasPrefixedId
{
    use HasUlids;

    /** The prefix of this model's ids, including the underscore. */
    abstract protected static function idPrefix(): string;

    public function newUniqueId(): string
    {
        return static::idPrefix().strtolower((string) Str::ulid());
    }

    /**
     * Route binding asks this before it queries: the default only accepts a bare ULID, which would
     * make every prefixed id a 404.
     */
    protected function isValidUniqueId(mixed $value): bool
    {
        $prefix = static::idPrefix();

        return is_string($value) && str_starts_with($value, $prefix) && Str::isUlid(substr($value, strlen($prefix)));
    }
}
