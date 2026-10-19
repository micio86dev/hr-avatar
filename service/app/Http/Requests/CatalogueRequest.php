<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The query of a catalogue listing: `fresh=1` skips the cache for this call.
 */
class CatalogueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['fresh' => ['sometimes', 'boolean']];
    }

    public function fresh(): bool
    {
        return $this->boolean('fresh');
    }
}
