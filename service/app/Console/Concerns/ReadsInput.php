<?php

namespace App\Console\Concerns;

use Illuminate\Console\Command;

/**
 * Typed reads of console arguments and options, which the framework returns as mixed.
 *
 * @mixin Command
 */
trait ReadsInput
{
    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    private function stringOption(string $name, ?string $default = null): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : $default;
    }
}
