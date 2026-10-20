<?php

namespace App\AvatarProviders;

/**
 * One setting an adapter accepts in an avatar config (or persona). The same definition drives the
 * validation and the catalogue endpoint, so what a client is told and what is enforced cannot drift.
 *
 * A setting that only takes effect with a bound external voice says so with `voiceEngines`: the
 * engines it applies to. Stored for any other voice it would be accepted and never sent. When the
 * allowed values also depend on the engine, `optionsByVoiceEngine` narrows `options` per engine
 * (`options` stays the flat union, so a client that ignores the narrowing still sees a legal list).
 */
final readonly class FieldSpec
{
    /**
     * @param  list<string>|null  $options  allowed values of a select
     * @param  string|null  $catalogueResource  the catalogue resource (`avatar`, `replica`, `pal`, `voice`) that lists valid values
     * @param  array<string, list<string>>|null  $optionsByVoiceEngine
     * @param  list<string>|null  $voiceEngines
     */
    public function __construct(
        public string $key,
        public FieldType $type,
        public bool $required = false,
        public ?array $options = null,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?string $catalogueResource = null,
        public ?array $optionsByVoiceEngine = null,
        public ?array $voiceEngines = null,
    ) {}

    /**
     * Only what the spec sets, beyond `key`, `type` and `required`: a client never sees an empty member.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'required' => $this->required,
            ...array_filter([
                'options' => $this->options,
                'min' => $this->min,
                'max' => $this->max,
                'catalogue_resource' => $this->catalogueResource,
                'options_by_voice_engine' => $this->optionsByVoiceEngine,
                'voice_engines' => $this->voiceEngines,
            ], fn (mixed $value) => $value !== null),
        ];
    }
}
