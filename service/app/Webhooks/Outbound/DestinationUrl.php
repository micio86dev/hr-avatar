<?php

namespace App\Webhooks\Outbound;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The rule for a webhook URL: https (plain http only where destinations are not enforced), a host, no
 * credentials, and nowhere the guard refuses. One class for the create and the update.
 */
final class DestinationUrl implements ValidationRule
{
    public function __construct(private readonly OutboundHostGuard $guard) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = $this->parts($value);
        $schemes = $this->guard->enforced() ? ['https'] : ['http', 'https'];

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), $schemes, true) || ($parts['host'] ?? '') === '') {
            $fail($this->guard->enforced() ? 'The :attribute must be an https URL.' : 'The :attribute must be an http or https URL.');

            return;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $fail('The :attribute must not contain credentials.');

            return;
        }

        if ($this->guard->isBlocked((string) $value)) {
            $fail('The :attribute must not point at a private or reserved address.');
        }
    }

    /**
     * The URL's parts, or false for anything that is not a string URL. A control character or a space is
     * refused outright: parsers disagree about what such a URL means.
     *
     * @return array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}|false
     */
    private function parts(mixed $value): array|false
    {
        if (! is_string($value) || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            return false;
        }

        return parse_url($value);
    }
}
