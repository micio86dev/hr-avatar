<?php

namespace App\Keys;

enum KeyType: string
{
    case Secret = 'secret';
    case Publishable = 'publishable';

    /** The marker that starts every key of this type and mode, for example `hra_sk_live_`. */
    public function marker(KeyMode $mode): string
    {
        return 'hra_'.$this->letters().'_'.$mode->value.'_';
    }

    /**
     * Random bytes behind the marker; the key shows them as twice as many hex characters.
     *
     * @return int<1, max>
     */
    public function randomBytes(): int
    {
        return match ($this) {
            self::Secret => 48,
            self::Publishable => 24,
        };
    }

    private function letters(): string
    {
        return match ($this) {
            self::Secret => 'sk',
            self::Publishable => 'pk',
        };
    }
}
