<?php

namespace App\AvatarProviders;

use Illuminate\Support\Facades\Log;

/**
 * How long a provider's or a voice vendor's paged list may take as a whole, and what is said when it is cut short
 * (by this deadline or by the page limit). One request runs the whole listing, so a vendor that answers every page
 * slowly, or never ends the list, must not hold a worker for pages times the per-call timeout.
 *
 * A list that is cut short is returned as far as it was read, and the warning (the list's name, never its content)
 * is the only signal: the catalogue endpoint caches the answer for five minutes, and `fresh=1` reads it again.
 */
final class ListBounds
{
    /** Seconds the whole listing may take, one page after the other. */
    public const DEADLINE_SECONDS = 30;

    private readonly int $deadline;

    public function __construct(private readonly string $name)
    {
        $this->deadline = now()->getTimestamp() + self::DEADLINE_SECONDS;
    }

    public function expired(): bool
    {
        return now()->getTimestamp() >= $this->deadline;
    }

    public function cut(): void
    {
        Log::warning('A provider list was cut short', ['list' => $this->name]);
    }
}
