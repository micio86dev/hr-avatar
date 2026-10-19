<?php

namespace App\Http\Requests;

use App\AvatarProviders\VoiceBinding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The body of a voice preview: which voice, and in which language to hear it. There is no member for the
 * sentence and no way to add one: any key outside these four is refused. The voice id is placed in a
 * vendor url, so it is limited to what an id is made of.
 */
class VoicePreviewRequest extends FormRequest
{
    private const MEMBERS = ['provider', 'voice_id', 'tts_engine', 'language'];

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
            'provider' => ['required', 'string', Rule::in(config()->array('hr-avatar.credential_providers'))],
            /** The vendor's id of the voice: 1 to 128 letters, digits, `_` or `-`. */
            'voice_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}\z/'],
            /**
             * The external engine the provider routes its voice through, or null. Needed to preview the voice of a provider
             * that has no voices of its own.
             *
             * @var 'cartesia'|'elevenlabs'|null
             */
            'tts_engine' => ['sometimes', 'nullable', 'string', Rule::in(array_values(array_diff(VoiceBinding::ENGINES, [VoiceBinding::PROVIDER_ENGINE])))],
            'language' => ['required', 'string', Rule::in(array_keys(config()->array('hr-avatar.voice_preview.phrases')))],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), self::MEMBERS) as $key) {
                $validator->errors()->add((string) $key, 'This field is not accepted.');
            }
        }];
    }

    public function engine(): ?string
    {
        return $this->has('tts_engine') ? $this->string('tts_engine')->toString() ?: null : null;
    }
}
