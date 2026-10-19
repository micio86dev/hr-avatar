<?php

namespace App\Keys;

/**
 * A label of the key space and its prefix. It never changes provider selection and never
 * partitions data: both modes read and write the same account's resources.
 */
enum KeyMode: string
{
    case Live = 'live';
    case Test = 'test';
}
