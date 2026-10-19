<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use stdClass;

// Each event's `data` is read from the raw JSON body: Laravel's parsed input turns `{}` into `[]` (not an
// object) and would trim and null the strings inside it.

/**
 * A batch of the browser's events. The batch is validated whole: one invalid event rejects all of them.
 */
class ClientEventsRequest extends FormRequest
{
    private const MAX_DATA_BYTES = 2048;

    /** An ISO 8601 date-time with a time zone, as a browser's `toISOString()` or any server writes it. */
    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})\z/';

    private ?stdClass $body = null;

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
            /** One to a hundred events, applied in this order. */
            'events' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'events.*' => ['array'],
            /** The browser's own key for the event; sending it again is counted as a duplicate and stored once. */
            'events.*.idempotency_key' => ['required', 'string', 'min:1', 'max:128'],
            /** What happened: an `utterance` somebody spoke, or a `lifecycle` signal of the call. */
            'events.*.type' => ['required', 'string', Rule::in(['utterance', 'lifecycle'])],
            /** When it happened, in ISO 8601 with a time zone. */
            'events.*.occurred_at' => ['sometimes', 'nullable', 'string', 'regex:'.self::TIMESTAMP, 'date'],
            /** Who spoke, for an utterance only. */
            'events.*.role' => ['required_if:events.*.type,utterance', 'prohibited_if:events.*.type,lifecycle', 'string', Rule::in(['user', 'avatar'])],
            /** What was said, 1 to 8000 characters, for an utterance only. */
            'events.*.text' => ['required_if:events.*.type,utterance', 'prohibited_if:events.*.type,lifecycle', 'string', 'min:1', 'max:8000'],
            /** The signal, for a lifecycle event only: `live` (the call is up) or `error`. */
            'events.*.name' => ['required_if:events.*.type,lifecycle', 'prohibited_if:events.*.type,utterance', 'string', Rule::in(['live', 'error'])],
            /**
             * Anything the browser wants to add, a JSON object of at most 2 KB.
             *
             * @var object|null
             */
            'events.*.data' => ['sometimes'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->rawEvents() as $index => $event) {
                $problem = $this->dataProblem($event instanceof stdClass ? ($event->data ?? null) : null);

                if ($problem !== null && ! $validator->errors()->has("events.{$index}.data")) {
                    $validator->errors()->add("events.{$index}.data", $problem);
                }
            }
        }];
    }

    /**
     * The validated batch, in order.
     *
     * @return list<array{idempotency_key: string, type: string, role: string|null, text: string|null, name: string|null, data: stdClass|null, occurred_at: string|null}>
     */
    public function events(): array
    {
        $raw = $this->rawEvents();
        $events = [];

        /** @var array<int, array<string, mixed>> $validated */
        $validated = $this->validated('events');

        foreach ($validated as $index => $event) {
            $data = $raw[$index]->data ?? null;
            $events[] = [
                'idempotency_key' => $event['idempotency_key'],
                'type' => $event['type'],
                'role' => $event['role'] ?? null,
                'text' => $event['text'] ?? null,
                'name' => $event['name'] ?? null,
                'data' => $data instanceof stdClass ? $data : null,
                'occurred_at' => isset($event['occurred_at']) ? Carbon::parse($event['occurred_at'])->utc()->format('Y-m-d H:i:s.u') : null,
            ];
        }

        return $events;
    }

    private function dataProblem(mixed $data): ?string
    {
        if ($data === null) {
            return null;
        }

        if (! $data instanceof stdClass) {
            return 'The data must be a JSON object.';
        }

        return strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > self::MAX_DATA_BYTES
            ? 'The data may not be larger than 2 KB when encoded.'
            : null;
    }

    /**
     * The events as the body spelled them (a JSON object stays an object); empty when it holds no list of them.
     *
     * @return list<mixed>
     */
    private function rawEvents(): array
    {
        // Form fields cannot say "object": whatever such a body calls `data` is an array, which is refused.
        if (! $this->isJson()) {
            $events = $this->input('events');

            return is_array($events) ? array_map(fn (mixed $event) => is_array($event) ? (object) $event : $event, array_values($events)) : [];
        }

        if ($this->body === null) {
            $decoded = json_decode($this->getContent(), false);
            $this->body = $decoded instanceof stdClass ? $decoded : new stdClass;
        }

        $events = $this->body->events ?? null;

        return is_array($events) ? array_values($events) : [];
    }
}
