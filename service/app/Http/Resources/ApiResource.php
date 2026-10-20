<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base of every resource: the body is the object itself (lists wrap their items in `data`
 * through PagedCollection). Timestamps are written with `toIso8601ZuluString()`, one format everywhere.
 */
abstract class ApiResource extends JsonResource
{
    public static $wrap = null;
}
