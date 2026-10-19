<?php

namespace App\Keys;

final readonly class ParsedKey
{
    public function __construct(public KeyType $type, public KeyMode $mode) {}
}
