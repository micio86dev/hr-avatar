<?php

namespace App\AvatarProviders;

final readonly class SessionStatus
{
    public function __construct(public bool $ended, public ?string $endReason) {}
}
