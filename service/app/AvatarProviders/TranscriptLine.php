<?php

namespace App\AvatarProviders;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * One utterance of a provider's transcript, normalized: the role is the user's or the avatar's,
 * never anything else, so a provider drifting to a new role is an error and not a coerced line.
 */
final readonly class TranscriptLine
{
    public const USER = 'user';

    public const AVATAR = 'avatar';

    public function __construct(
        public string $role,
        public string $text,
        public ?DateTimeInterface $occurredAt,
        public ?float $offsetSeconds,
    ) {
        if ($role !== self::USER && $role !== self::AVATAR) {
            throw new InvalidArgumentException('A transcript line is spoken by the user or the avatar.');
        }
    }
}
