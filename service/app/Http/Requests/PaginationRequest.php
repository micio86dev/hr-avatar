<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;

/**
 * `limit` (1-100, default 25) and an opaque `cursor` for every list over a growing collection.
 * Laravel silently serves the first page for a cursor it cannot read, and binds a hand-made
 * cursor straight into the query, so the cursor is checked down to its two members here.
 */
class PaginationRequest extends FormRequest
{
    private const DEFAULT_LIMIT = 25;

    private const TIMESTAMP = '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])[T ]([01]\d|2[0-3]):[0-5]\d:[0-5]\d(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})?\z/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:512', function (string $attribute, mixed $value, callable $fail): void {
                if (! is_string($value) || self::decode($value) === null) {
                    $fail('The cursor is not valid.');
                }
            }],
        ];
    }

    /**
     * One page of the query, newest first with the id as tiebreaker.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return CursorPaginator<int, TModel>
     */
    public function page(Builder $query): CursorPaginator
    {
        $cursor = $this->has('cursor') ? self::decode($this->string('cursor')->toString()) : null;

        return $query->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(perPage: $this->integer('limit', self::DEFAULT_LIMIT), cursor: $cursor);
    }

    /**
     * The cursor only when it is exactly what this service issues: a timestamp, an id and a direction.
     */
    private static function decode(string $encoded): ?Cursor
    {
        $cursor = Cursor::fromEncoded($encoded);

        if ($cursor === null) {
            return null;
        }

        $members = $cursor->toArray();
        ksort($members);

        if (array_keys($members) !== ['_pointsToNextItems', 'created_at', 'id']) {
            return null;
        }

        $valid = is_bool($members['_pointsToNextItems'])
            && is_string($members['created_at']) && preg_match(self::TIMESTAMP, $members['created_at']) === 1
            && checkdate((int) substr($members['created_at'], 5, 2), (int) substr($members['created_at'], 8, 2), (int) substr($members['created_at'], 0, 4))
            && is_string($members['id']) && strlen($members['id']) <= 64;

        return $valid ? $cursor : null;
    }
}
