<?php

namespace App\AvatarProviders;

/**
 * What a provider lets a client configure and look up, without any call to the provider: the
 * settings of the config and of the persona, the catalogue resources it can list, and the voice
 * engines it can speak with.
 */
final readonly class ProviderDescription
{
    /**
     * @param  list<FieldSpec>  $fields  settings of the avatar config's `config`
     * @param  list<FieldSpec>  $personaFields  settings of the avatar config's `persona`
     * @param  list<string>  $resources
     * @param  list<string>  $voiceEngines
     */
    public function __construct(
        public array $fields,
        public array $personaFields,
        public array $resources,
        public array $voiceEngines,
    ) {}

    /**
     * @return array{fields: list<array<string, mixed>>, persona_fields: list<array<string, mixed>>, resources: list<string>, voice_engines: list<string>}
     */
    public function toArray(): array
    {
        return [
            'fields' => array_map(fn (FieldSpec $spec) => $spec->toArray(), $this->fields),
            'persona_fields' => array_map(fn (FieldSpec $spec) => $spec->toArray(), $this->personaFields),
            'resources' => $this->resources,
            'voice_engines' => $this->voiceEngines,
        ];
    }
}
