<?php

use App\Webhooks\Outbound\DestinationNotAllowed;
use App\Webhooks\Outbound\OutboundHostGuard;

/**
 * A guard that resolves every name to the given addresses, and records what it was asked for.
 *
 * @param  list<string>  $addresses
 * @param  list<string>  $asked
 */
function guardResolving(array $addresses, array &$asked = []): OutboundHostGuard
{
    return new OutboundHostGuard(true, function (string $host) use ($addresses, &$asked): array {
        $asked[] = $host;

        return $addresses;
    });
}

function guardWithNoDns(): OutboundHostGuard
{
    return new OutboundHostGuard(true, fn () => throw new LogicException('No lookup expected.'));
}

describe('outside local and testing it is not enforced', function () {
    it('lets every destination through without a lookup, so a developer can reach their own machine', function (string $url) {
        $guard = new OutboundHostGuard(false, fn () => throw new LogicException('No lookup expected.'));

        expect($guard->enforced())->toBeFalse()
            ->and($guard->isBlocked($url))->toBeFalse()
            ->and($guard->vettedAddresses($url))->toBe([]);
    })->with(['http://localhost:8080/hook', 'https://10.0.0.5/hook', 'https://169.254.169.254/latest', 'http://[::1]/hook']);

    it('is enforced by default: forgetting the flag fails closed', function () {
        expect((new OutboundHostGuard)->enforced())->toBeTrue();
    });
});

describe('literal addresses', function () {
    it('blocks loopback, private, link-local, shared, benchmarking, multicast and reserved IPv4', function (string $ip) {
        expect(guardWithNoDns()->isBlocked("https://{$ip}/hook"))->toBeTrue();
    })->with([
        'loopback' => '127.0.0.1', 'loopback range' => '127.8.9.10', 'any' => '0.0.0.0', 'this network' => '0.1.2.3',
        '10/8' => '10.0.0.1', '10/8 top' => '10.255.255.255', '172.16/12' => '172.16.0.1', '172.16/12 top' => '172.31.255.255',
        '192.168/16' => '192.168.1.1', 'link-local' => '169.254.1.1', 'cloud metadata' => '169.254.169.254',
        'shared address space' => '100.64.0.1', 'shared address space top' => '100.127.255.255', 'a cloud metadata address in it' => '100.100.100.200',
        'protocol assignments' => '192.0.0.8', 'benchmarking' => '198.18.0.1', 'benchmarking top' => '198.19.255.255',
        'multicast' => '224.0.0.1', 'reserved' => '240.0.0.1', 'broadcast' => '255.255.255.255',
    ]);

    it('allows public IPv4, including the neighbours of the blocked ranges', function (string $ip) {
        expect(guardWithNoDns()->isBlocked("https://{$ip}/hook"))->toBeFalse()
            ->and(guardWithNoDns()->vettedAddresses("https://{$ip}:8443/hook"))->toBe([$ip]);
    })->with(['8.8.8.8', '1.1.1.1', '93.184.216.34', '100.63.255.255', '100.128.0.1', '198.17.255.255', '198.20.0.1', '172.15.255.255', '172.32.0.1', '11.0.0.1', '169.253.1.1']);

    it('blocks loopback, unique-local, link-local, multicast, NAT64 and IPv4-mapped IPv6, bracketed as a URL holds them', function (string $ip) {
        expect(guardWithNoDns()->isBlocked("https://[{$ip}]/hook"))->toBeTrue();
    })->with([
        'loopback' => '::1', 'unspecified' => '::', 'unique local' => 'fd00::1', 'unique local fc' => 'fc00::1', 'link-local' => 'fe80::1',
        'mapped metadata' => '::ffff:169.254.169.254', 'mapped private' => '::ffff:10.0.0.1', 'mapped loopback' => '::ffff:127.0.0.1',
        'NAT64 of a private address' => '64:ff9b::a00:1', 'multicast' => 'ff02::1',
    ]);

    it('allows a public IPv6 literal', function () {
        expect(guardWithNoDns()->isBlocked('https://[2001:4860:4860::8888]/hook'))->toBeFalse()
            ->and(guardWithNoDns()->vettedAddresses('https://[2001:4860:4860::8888]/hook'))->toBe(['2001:4860:4860::8888']);
    });

    it('blocks the numeric spellings of an address that a resolver would turn into a private one', function (string $host) {
        expect(guardWithNoDns()->isBlocked("https://{$host}/hook"))->toBeTrue();
    })->with(['decimal' => '2130706433', 'hex' => '0x7f000001', 'dotted hex' => '0x7f.0.0.1', 'octal' => '0177.0.0.1', 'short' => '127.1', 'mixed' => '10.1']);
});

describe('the URL itself', function () {
    it('blocks what is not a https URL with a host', function (string $url) {
        expect(guardWithNoDns()->isBlocked($url))->toBeTrue();
    })->with(['http://8.8.8.8/hook', 'ftp://8.8.8.8/hook', 'file:///etc/passwd', 'gopher://8.8.8.8', '//8.8.8.8/hook', 'https:///hook', 'https://', 'not a url at all', '', 'HTTP://8.8.8.8/hook']);

    it('accepts an upper-case https scheme', function () {
        expect(guardWithNoDns()->isBlocked('HTTPS://8.8.8.8/hook'))->toBeFalse();
    });

    it('refuses to vet a blocked URL, naming nothing of it', function () {
        try {
            guardWithNoDns()->vettedAddresses('https://10.0.0.5/secret-path?token=abc');
            $this->fail('A blocked destination was vetted.');
        } catch (DestinationNotAllowed $blocked) {
            expect($blocked->getMessage())->not->toContain('10.0.0.5')->not->toContain('secret-path')->not->toContain('abc');
        }
    });
});

describe('hostnames', function () {
    it('resolves the host and allows it when every address is public', function () {
        $asked = [];
        $guard = guardResolving(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'], $asked);

        expect($guard->isBlocked('https://hooks.example.com:8443/path'))->toBeFalse()
            ->and($guard->vettedAddresses('https://hooks.example.com/path'))->toBe(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'])
            ->and($asked)->toBe(['hooks.example.com', 'hooks.example.com']);
    });

    it('blocks the host when any address it resolves to is not public', function (array $addresses) {
        expect(guardResolving($addresses)->isBlocked('https://hooks.example.com/hook'))->toBeTrue();
    })->with([
        'only a private one' => [['10.0.0.5']],
        'metadata, as in DNS rebinding' => [['169.254.169.254']],
        'one public and one private' => [['93.184.216.34', '192.168.0.1']],
        'a public one first, loopback last' => [['93.184.216.34', '127.0.0.1']],
        'only IPv6 loopback' => [['::1']],
        'a public IPv4 and a unique-local IPv6' => [['93.184.216.34', 'fd00::5']],
        'an answer that is no address at all' => [['not-an-ip']],
    ]);

    it('does not connect to a host that does not resolve: it is allowed here, and there is nothing to pin', function () {
        $guard = guardResolving([]);

        expect($guard->isBlocked('https://nowhere.invalid/hook'))->toBeFalse()->and($guard->vettedAddresses('https://nowhere.invalid/hook'))->toBe([]);
    });

    it('resolves a host name only: never a literal address', function () {
        $asked = [];

        guardResolving(['93.184.216.34'], $asked)->vettedAddresses('https://8.8.8.8/hook');

        expect($asked)->toBe([]);
    });

    it('resolves with the system resolver by default: localhost is blocked', function () {
        expect((new OutboundHostGuard)->isBlocked('https://localhost/hook'))->toBeTrue()
            ->and((new OutboundHostGuard)->isBlocked('https://localhost.:8443/hook'))->toBeTrue();
    });

    it('treats a host that does not exist as unresolvable with the system resolver', function () {
        expect((new OutboundHostGuard)->vettedAddresses('https://webhooks.invalid/hook'))->toBe([]);
    });
});
