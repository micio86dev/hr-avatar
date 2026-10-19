<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of a credential write, shared by provider and language-model credentials.
 */
class PutCredentialRequest extends FormRequest
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
        return ['api_key' => ['required', 'string', 'min:1', 'max:512']];
    }

    public function apiKey(): string
    {
        return $this->string('api_key')->toString();
    }
}
