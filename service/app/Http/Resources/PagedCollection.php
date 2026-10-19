<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A page of a growing list as `{data, next_cursor}`: Laravel's default `links` and `meta` blocks
 * describe page numbers the cursor convention does not have.
 */
class PagedCollection extends AnonymousResourceCollection
{
    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return ['next_cursor' => $paginated['next_cursor']];
    }
}
