<?php

namespace App\AvatarProviders;

/**
 * One problem found in an avatar config: the dotted request field (`config.avatarId`) and a stable
 * code. The sentence is fixed English and never quotes the value, which may be anything a client sent.
 */
final readonly class FieldError
{
    public function __construct(public string $field, public string $code) {}

    public function message(): string
    {
        return match ($this->code) {
            'required' => 'This field is required.',
            'type' => 'The value has the wrong type.',
            'range' => 'The value is outside the allowed range.',
            'enum' => 'The value is not one of the allowed values.',
            'unknown' => 'This setting is not recognised for this provider.',
            'requires_voice_engine' => 'This setting applies only to a bound voice from a matching external engine.',
            'unsupported' => 'This provider does not support that value.',
            default => 'The value is not valid.',
        };
    }
}
