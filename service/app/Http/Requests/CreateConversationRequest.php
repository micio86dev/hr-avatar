<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use stdClass;

// `metadata` is read from the raw JSON body: Laravel's parsed input turns `{}` into `[]`, and an empty
// list is not an object.

/**
 * The body of a conversation create. `external_ref` and `metadata` are the host's own and are carried untouched.
 */
class CreateConversationRequest extends FormRequest
{
    /** What a conversation lasts when the host does not say (or the ceiling, if that is lower). */
    private const DEFAULT_DURATION_SECONDS = 1800;

    private const MIN_DURATION_SECONDS = 30;

    private const MAX_METADATA_BYTES = 8192;

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
            /** The avatar config to talk with: one of the account's, synchronized if its provider needs it. */
            'avatar_config_id' => ['required', 'string'],
            /** The instructions the avatar follows. Kept as sent, whitespace included. */
            'system_prompt' => ['required', 'string', 'max:32000'],
            /** The two-letter ISO 639-1 code of the conversation's language, in lower case. */
            'language' => ['required', 'string', 'regex:/^[a-z]{2}\z/'],
            /** What the avatar says first, if anything. */
            'opening_text' => ['sometimes', 'nullable', 'string', 'max:4000'],
            /** How long the conversation may last, 30 seconds up to the platform ceiling. Defaults to 1800 (or the ceiling, if lower). */
            'max_duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:'.self::MIN_DURATION_SECONDS, 'max:'.$this->ceiling()],
            /** The host's own reference, returned as sent and never interpreted. */
            'external_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            /**
             * The host's own JSON object, up to 8 KB encoded, returned exactly as sent and never interpreted.
             *
             * @var object|null
             */
            'metadata' => ['sometimes'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $problem = $this->metadataProblem();

            if ($problem !== null) {
                $validator->errors()->add('metadata', $problem);
            }
        }];
    }

    /**
     * The members of a new conversation, as validated. The provider is the config's and is added by the caller.
     *
     * @return array{avatar_config_id: string, system_prompt: string, language: string, opening_text: string|null, max_duration_seconds: int, external_ref: string|null, metadata: stdClass}
     */
    public function values(): array
    {
        $values = $this->validated();

        return [
            'avatar_config_id' => $values['avatar_config_id'],
            'system_prompt' => $values['system_prompt'],
            'language' => $values['language'],
            'opening_text' => $values['opening_text'] ?? null,
            'max_duration_seconds' => (int) ($values['max_duration_seconds'] ?? min(self::DEFAULT_DURATION_SECONDS, $this->ceiling())),
            'external_ref' => $values['external_ref'] ?? null,
            'metadata' => $this->metadata(),
        ];
    }

    private function ceiling(): int
    {
        return config()->integer('hr-avatar.max_duration_ceiling');
    }

    private function metadata(): stdClass
    {
        $metadata = $this->rawMetadata();

        return $metadata instanceof stdClass ? $metadata : new stdClass;
    }

    /**
     * Why the metadata cannot be used, or null when it is absent, null or an object of at most 8 KB.
     */
    private function metadataProblem(): ?string
    {
        $metadata = $this->rawMetadata();

        if ($metadata === null) {
            return null;
        }

        if (! $metadata instanceof stdClass) {
            return 'The metadata must be a JSON object.';
        }

        // Compact and unescaped, which is the smallest honest reading of "serialized".
        return strlen(json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > self::MAX_METADATA_BYTES
            ? 'The metadata may not be larger than 8 KB when encoded.'
            : null;
    }

    /**
     * The value as the JSON body spelled it: an object stays an object, even an empty one. A body that
     * is not JSON can only carry arrays and scalars, which are never an object.
     */
    private function rawMetadata(): mixed
    {
        return $this->isJson() ? ($this->decodedBody()->metadata ?? null) : $this->input('metadata');
    }

    private function decodedBody(): stdClass
    {
        if ($this->body === null) {
            $decoded = json_decode($this->getContent(), false);
            $this->body = $decoded instanceof stdClass ? $decoded : new stdClass;
        }

        return $this->body;
    }
}
