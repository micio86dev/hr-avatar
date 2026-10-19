<?php

namespace App\Http;

use RuntimeException;

/**
 * Thrown anywhere in the request lifecycle to answer with a specific Problem+JSON response.
 */
class ApiProblem extends RuntimeException
{
    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        public readonly ?string $detail = null,
        public readonly ?array $errors = null,
        public readonly array $headers = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($detail ?? $errorCode->defaultDetail());
    }
}
