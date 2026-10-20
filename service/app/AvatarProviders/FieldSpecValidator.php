<?php

namespace App\AvatarProviders;

/**
 * Checks a settings object against the field specs an adapter declares, and returns every problem
 * at once (a form filled in over seventeen round trips is not a form): unknown keys first, then the
 * specs in order. A null value counts as absent, so clearing a setting is possible.
 *
 * The codes are distinct because each needs different words: `required`, `type`, `range`, `enum`,
 * `unknown`, `requires_voice_engine` (the setting would be stored and never sent) and, for the
 * voice itself, `unsupported`.
 */
final class FieldSpecValidator
{
    /**
     * @param  list<FieldSpec>  $specs
     * @param  array<array-key, mixed>  $data
     * @param  string  $path  the request member the data came from (`config`, `persona`), prefixed to every field
     * @param  bool  $voiceRefused  the voice's engine was refused: a voice-gated setting is judged by its type and range only
     * @return list<FieldError>
     */
    public static function validate(array $specs, array $data, ?VoiceBinding $voice, string $path, bool $voiceRefused = false): array
    {
        $known = array_map(fn (FieldSpec $spec) => $spec->key, $specs);
        $errors = [];

        foreach (array_keys($data) as $key) {
            if (! in_array((string) $key, $known, true)) {
                $errors[] = new FieldError("{$path}.{$key}", 'unknown');
            }
        }

        foreach ($specs as $spec) {
            $value = $data[$spec->key] ?? null;
            $code = $value === null ? ($spec->required ? 'required' : null) : self::check($spec, $value, $voice, $voiceRefused);

            if ($code !== null) {
                $errors[] = new FieldError("{$path}.{$spec->key}", $code);
            }
        }

        return $errors;
    }

    /**
     * What an adapter's `validateConfig` returns: the voice engine against the engines the provider
     * speaks with, then the config and the persona against their specs. When the provider refused
     * the voice, the rest is still checked, but a setting is not also blamed for lacking a voice, and a
     * voice-gated setting is still judged by its type and range (every problem at once, in one round trip).
     *
     * @param  array<array-key, mixed>  $config
     * @param  array<array-key, mixed>  $persona
     * @return list<FieldError>
     */
    public static function validateConfig(ProviderDescription $description, array $config, ?VoiceBinding $voice, array $persona = []): array
    {
        $refused = $voice !== null && ! in_array($voice->engine, $description->voiceEngines, true);

        $errors = [
            ...self::validate($description->fields, $config, $refused ? null : $voice, 'config', $refused),
            ...self::validate($description->personaFields, $persona, $refused ? null : $voice, 'persona', $refused),
        ];

        return $refused ? [new FieldError('voice.engine', 'unsupported'), ...$errors] : $errors;
    }

    private static function check(FieldSpec $spec, mixed $value, ?VoiceBinding $voice, bool $voiceRefused): ?string
    {
        if (! $voiceRefused && $spec->voiceEngines !== null && ($voice === null || ! in_array($voice->engine, $spec->voiceEngines, true))) {
            return 'requires_voice_engine';
        }

        return match ($spec->type) {
            FieldType::Text => is_string($value) ? null : 'type',
            FieldType::Checkbox => is_bool($value) ? null : 'type',
            FieldType::Select => self::select($spec, $value, $voice, $voiceRefused),
            FieldType::Integer => is_int($value) ? self::range($spec, $value) : 'type',
            FieldType::Number => is_int($value) || is_float($value) ? self::range($spec, $value) : 'type',
        };
    }

    private static function select(FieldSpec $spec, mixed $value, ?VoiceBinding $voice, bool $voiceRefused): ?string
    {
        if (! is_string($value)) {
            return 'type';
        }

        if ($voiceRefused && $spec->optionsByVoiceEngine !== null) {
            // The options depend on the engine, and the engine was refused: only the type can be judged.
            return null;
        }

        $allowed = $spec->optionsByVoiceEngine !== null && $voice !== null
            ? ($spec->optionsByVoiceEngine[$voice->engine] ?? [])
            : ($spec->options ?? []);

        return in_array($value, $allowed, true) ? null : 'enum';
    }

    private static function range(FieldSpec $spec, int|float $value): ?string
    {
        return ($spec->min !== null && $value < $spec->min) || ($spec->max !== null && $value > $spec->max) ? 'range' : null;
    }
}
