<?php

namespace App\AvatarProviders;

use stdClass;

/**
 * What a provider needs to know about one conversation, in host-neutral terms. `metadata` is the
 * host's opaque object and is only carried, never read.
 */
final readonly class ConversationSpec
{
    public function __construct(
        public string $systemPrompt,
        public ?string $openingText,
        public string $language,
        public int $maxDurationSeconds,
        public stdClass $metadata,
    ) {}
}
