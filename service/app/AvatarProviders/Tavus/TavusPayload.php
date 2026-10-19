<?php

namespace App\AvatarProviders\Tavus;

use App\AvatarProviders\ConversationSpec;
use App\Models\AvatarConfig;

/**
 * The body of Tavus's create-conversation call. Pure: it reads the spec and the avatar config and
 * nothing else, and a value that is not set is absent from the body, never sent empty (Tavus
 * rejects a request with neither a replica nor a persona, and ignores a field at a wrong path).
 */
final class TavusPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function forConversation(ConversationSpec $spec, AvatarConfig $config, ?string $callbackUrl): array
    {
        $body = ['conversational_context' => $spec->systemPrompt];

        if ($spec->openingText !== null && $spec->openingText !== '') {
            $body['custom_greeting'] = $spec->openingText;
        }

        if ($callbackUrl !== null) {
            $body['callback_url'] = $callbackUrl;
        }

        foreach (['replica_id', 'persona_id'] as $key) {
            $value = $config->config[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $body[$key] = $value;
            }
        }

        $properties = [
            'language' => TavusLanguage::forWire($spec->language),
            'max_call_duration' => $spec->maxDurationSeconds,
        ];

        // A conversation that should end once the browser leaves says so: the unset default was
        // observed not to end it.
        $leftTimeout = $config->config['participant_left_timeout'] ?? null;

        if (is_int($leftTimeout)) {
            $properties['participant_left_timeout'] = $leftTimeout;
        }

        return $body + ['properties' => $properties];
    }
}
