<?php

namespace App\AvatarProviders;

/**
 * How a provider setting is entered and checked. An enum rather than a string so that a new type
 * has to be handled by the validator's exhaustive match.
 */
enum FieldType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Number = 'number';
    case Select = 'select';
    case Checkbox = 'checkbox';
}
