<?php

namespace App\Webhooks\Outbound;

use Closure;

/**
 * Decides whether a webhook may be sent to a URL: https, and a host whose every address is public.
 * Used when an endpoint is created or changed, and again at delivery time on the addresses the
 * connection will really use (the caller pins the request to them, so a name that now resolves
 * elsewhere cannot redirect it).
 *
 * Blocked: loopback, private (RFC 1918, RFC 4193), link-local (the cloud metadata address included),
 * shared address space, benchmarking and protocol-assignment ranges, multicast, reserved ranges, every
 * IPv6 spelling of an IPv4 address (mapped, translated, compatible and 6to4: the embedded IPv4 address is
 * judged by the IPv4 rules, whatever the spelling), the whole NAT64 range, and every spelling of an address
 * as a bare number. A value that looks like an IPv6 literal but is not an address (a zone identifier) is blocked.
 * A host that does not resolve at all is allowed here, because nothing can connect to it; a delivery
 * finds no address to pin and refuses to send (see `DeliverWebhook`).
 *
 * Not enforced in `local` and `testing`, so a developer can receive a webhook on their own machine.
 * The default is enforced: a caller that forgets to say so fails closed.
 */
final class OutboundHostGuard
{
    /**
     * Ranges the platform's own address filter does not cover: shared address space (it holds a cloud
     * metadata address), protocol assignments, benchmarking, multicast, NAT64 and IPv6 multicast.
     */
    private const ALSO_BLOCKED = ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '64:ff9b::/96', '64:ff9b:1::/48', 'ff00::/8'];

    /** A host made only of decimal, octal or hexadecimal numbers: a resolver may read it as an address. */
    private const NUMERIC_HOST = '/^(0x[0-9a-f]*|\d+)(\.(0x[0-9a-f]*|\d+))*$/i';

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  Finds the addresses of a host name; the system resolver when null.
     */
    public function __construct(private readonly bool $enforced = true, private readonly ?Closure $resolver = null) {}

    public function enforced(): bool
    {
        return $this->enforced;
    }

    /**
     * Whether a URL is refused. False for every URL while destinations are not enforced (a developer's
     * machine), and false for a host that does not resolve; the delivery path decides what to do with those.
     */
    public function isBlocked(string $url): bool
    {
        try {
            $this->vettedAddresses($url);
        } catch (DestinationNotAllowed) {
            return true;
        }

        return false;
    }

    /**
     * The addresses a connection to the URL may use: the literal itself, or what the host name resolves
     * to (none when it does not resolve, and none when destinations are not enforced).
     *
     * @return list<string>
     *
     * @throws DestinationNotAllowed when the URL, or any address it leads to, is not allowed
     */
    public function vettedAddresses(string $url): array
    {
        if (! $this->enforced) {
            return [];
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            throw new DestinationNotAllowed;
        }

        $host = $parts['host'];

        // parse_url keeps the brackets of an IPv6 literal, which the address filter does not accept.
        $host = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->allowed($host) ? [$host] : throw new DestinationNotAllowed;
        }

        // A host name never holds a colon: this is an IPv6 literal the address filter did not accept (a zone
        // identifier, say), and a resolver may still read it as an address.
        if (str_contains($host, ':') || str_contains($host, '[') || preg_match(self::NUMERIC_HOST, $host) === 1) {
            throw new DestinationNotAllowed;
        }

        $addresses = $this->resolve($host);

        foreach ($addresses as $address) {
            if (! $this->allowed($address)) {
                throw new DestinationNotAllowed;
            }
        }

        return $addresses;
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        // Both record types: a name with only an AAAA record is as reachable as one with an A record.
        // A failed lookup warns, and a warning must not become an exception here.
        $v4 = @gethostbynamel($host);
        $v6 = @dns_get_record($host, DNS_AAAA);

        return [...($v4 === false ? [] : $v4), ...($v6 === false ? [] : array_column($v6, 'ipv6'))];
    }

    private function allowed(string $address): bool
    {
        // One step for every spelling of an IPv4 address inside an IPv6 one: the IPv4 rules decide.
        $address = self::embeddedIpv4($address) ?? $address;

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::ALSO_BLOCKED as $range) {
            if (self::inRange($address, $range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The IPv4 address an IPv6 address carries, or null when it carries none: IPv4-mapped (`::ffff:a.b.c.d`),
     * IPv4-translated (`::ffff:0:a.b.c.d`), IPv4-compatible (`::a.b.c.d`) and 6to4 (`2002:ab:cd::/48`).
     * NAT64 is not here: the whole range is blocked.
     */
    private static function embeddedIpv4(string $address): ?string
    {
        $packed = inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $zeros = static fn (int $from, int $length): bool => substr($packed, $from, $length) === str_repeat("\0", $length);

        $embedded = match (true) {
            $zeros(0, 10) && substr($packed, 10, 2) === "\xff\xff" => substr($packed, 12, 4),
            $zeros(0, 8) && substr($packed, 8, 4) === "\xff\xff\0\0" => substr($packed, 12, 4),
            $zeros(0, 12) => substr($packed, 12, 4),
            str_starts_with($packed, "\x20\x02") => substr($packed, 2, 4),
            default => null,
        };

        return $embedded === null ? null : (inet_ntop($embedded) ?: null);
    }

    private static function inRange(string $address, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $packed = inet_pton($address);
        $base = inet_pton($network);

        // An address of the other family is never in the range.
        if ($packed === false || $base === false || strlen($packed) !== strlen($base)) {
            return false;
        }

        $whole = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;

        if (substr($packed, 0, $whole) !== substr($base, 0, $whole)) {
            return false;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return $rest === 0 || (ord($packed[$whole]) & $mask) === (ord($base[$whole]) & $mask);
    }
}
