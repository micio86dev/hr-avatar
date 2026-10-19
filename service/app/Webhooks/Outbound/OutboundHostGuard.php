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
 * shared address space, benchmarking and protocol-assignment ranges, multicast, reserved ranges, the
 * IPv4-mapped and NAT64 forms of any of them, and every spelling of an address as a bare number.
 * A host that does not resolve at all is allowed here: nothing can connect to it, and the delivery
 * finds no address to pin.
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
    private const ALSO_BLOCKED = ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '64:ff9b::/96', 'ff00::/8'];

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
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || $host === '') {
            throw new DestinationNotAllowed;
        }

        // parse_url keeps the brackets of an IPv6 literal, which the address filter does not accept.
        $host = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->allowed($host) ? [$host] : throw new DestinationNotAllowed;
        }

        if (preg_match(self::NUMERIC_HOST, $host) === 1) {
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
