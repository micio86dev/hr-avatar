<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RotateApiKeyRequest extends FormRequest
{
    public const DEFAULT_OVERLAP_SECONDS = 3600;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['overlap_seconds' => ['sometimes', 'integer', 'between:0,604800']];
    }

    public function overlapSeconds(): int
    {
        return $this->integer('overlap_seconds', self::DEFAULT_OVERLAP_SECONDS);
    }
}
