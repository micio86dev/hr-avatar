<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The envelope of a provider callback: a JSON object with the conversation, the event and its time. What a
 * provider adds beyond that is kept as sent. The callback address carries the credential, and a provider may
 * echo it, so the token is taken out of the body before anything reads it.
 */
class ProviderCallbackRequest extends FormRequest
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
        return [
            /** The provider's own id of the conversation; it must be the one the callback address was minted for. */
            'conversation_id' => ['required', 'string'],
            /** What happened, in the provider's words. */
            'event_type' => ['required', 'string'],
            /** When it happened, as the provider wrote it. */
            'timestamp' => ['required', 'string'],
        ];
    }

    /**
     * The body as sent, token removed; empty when it is not a JSON object.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $decoded = json_decode(str_replace((string) $this->route('token'), '[redacted]', $this->getContent()), true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->payload();
    }
}
