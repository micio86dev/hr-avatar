<?php

namespace App\Http\Requests;

/**
 * A page of avatar configs, optionally only those carrying one exact `external_ref`.
 */
class ListAvatarConfigsRequest extends PaginationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'external_ref' => ['sometimes', 'string', 'min:1', 'max:255']];
    }

    /**
     * The reference to match exactly, or null for all.
     */
    public function externalRef(): ?string
    {
        return $this->has('external_ref') ? $this->string('external_ref')->toString() : null;
    }
}
