<?php

namespace App\Http\Requests;

use App\Webhooks\Outbound\DestinationUrl;
use App\Webhooks\Outbound\EventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The members a webhook endpoint create and update share. Neither can name the secret or the owner:
 * only the members below are ever read.
 */
abstract class WebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `required` for a create, `sometimes` for an update.
     */
    abstract protected function presence(): string;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Where the events are sent: an https URL of at most 2048 characters, without credentials and not on a private address. */
            'url' => [$this->presence(), 'string', 'max:2048', app(DestinationUrl::class)],
            /**
             * The events to receive. All three when left out.
             *
             * @var list<'conversation.live'|'conversation.ended'|'transcript.ready'>
             */
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', 'distinct', Rule::enum(EventType::class)],
            /** Whether events are sent to this endpoint. */
            'enabled' => ['sometimes', 'boolean'],
            /** A note of the host's own, up to 255 characters. */
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The members as validated, and nothing else.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return array_intersect_key($this->validated(), array_flip(['url', 'events', 'enabled', 'description']));
    }
}
