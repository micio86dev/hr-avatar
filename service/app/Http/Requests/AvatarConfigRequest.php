<?php

namespace App\Http\Requests;

use App\AvatarProviders\ProviderRegistry;
use App\AvatarProviders\VoiceBinding;
use App\Models\AvatarConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The members a create and an update share, and the one check neither can express as a rule: the
 * `config`, `persona` and `voice` that would result are valid for the config's provider. On an
 * update that is the stored state with the sent members laid over it, so changing the voice
 * engine cannot leave a stored voice setting that the new engine does not take.
 */
abstract class AvatarConfigRequest extends FormRequest
{
    private const CHECKED_MEMBERS = ['provider', 'config', 'persona', 'voice'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The provider the config is, or will be, bound to.
     */
    abstract protected function providerId(): string;

    /**
     * The stored config an update starts from; null for a create.
     */
    abstract protected function existing(): ?AvatarConfig;

    /**
     * `required` for a create, `sometimes` for an update.
     */
    abstract protected function presence(): string;

    /**
     * @return list<mixed>
     */
    abstract protected function providerRules(): array;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->presence(), 'string', 'min:1', 'max:120'],
            'provider' => $this->providerRules(),
            /**
             * The provider-specific settings. The accepted keys depend on the provider: see `GET /v1/catalogue`.
             *
             * @var array<string, mixed>
             */
            'config' => ['sometimes', 'array'],
            /**
             * Provider-specific persona settings, or null for none.
             *
             * @var array<string, mixed>|null
             */
            'persona' => ['sometimes', 'nullable', 'array'],
            'llm_model_id' => ['sometimes', 'nullable', 'string', Rule::exists('llm_models', 'id')->where('enabled', true)],
            /**
             * The voice to speak with, or null for the provider's default. `engine` must be one the provider speaks with.
             *
             * @var array{engine: 'provider'|'cartesia'|'elevenlabs', voice_id: string}|null
             */
            'voice' => ['sometimes', 'nullable', 'array', 'required_array_keys:engine,voice_id'],
            'voice.engine' => ['string', Rule::in(VoiceBinding::ENGINES)],
            'voice.voice_id' => ['string', 'min:1', 'max:128'],
            'external_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // An update that sends none of the checked members leaves a state that was valid when stored.
            $untouched = $this->existing() !== null && ! $this->hasAny(['config', 'persona', 'voice']);

            if ($untouched || $this->hasProblem($validator)) {
                return;
            }

            $config = $this->member('config');
            $voice = $this->member('voice');
            $persona = $this->member('persona');

            $errors = app(ProviderRegistry::class)->get($this->providerId())->validateConfig(
                is_array($config) ? $config : [],
                VoiceBinding::fromArray(is_array($voice) ? $voice : null),
                is_array($persona) ? $persona : [],
            );

            foreach ($errors as $error) {
                $validator->errors()->add($error->field, $error->message());
            }
        }];
    }

    /**
     * Whether a member the check reads is already invalid; the rules say why.
     */
    private function hasProblem(Validator $validator): bool
    {
        foreach (array_keys($validator->errors()->messages()) as $field) {
            if (in_array(explode('.', $field)[0], self::CHECKED_MEMBERS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The member as the request would leave it: the value sent, else the stored one.
     */
    private function member(string $key): mixed
    {
        return $this->has($key) ? $this->input($key) : $this->existing()?->getAttribute($key);
    }
}
