<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;

/**
 * Validates URLs fetched by the extension marketplace.
 *
 * Repository metadata is administrator-controlled, while archive URLs are
 * repository-controlled. Neither is trusted to make the panel an HTTP client
 * for loopback, private, link-local, documentation, or otherwise special-use
 * networks.
 */
class ExtensionRemoteUrlGuard
{
    /**
     * @return array{host: string, port: int, addresses: array<int, string>, literalIp: bool}
     */
    public function assertSafeHttpsUrl(string $url, string $label): array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
        ) {
            throw new DisplayException(sprintf('%s must use HTTPS.', $label));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new DisplayException(sprintf('%s must not contain credentials.', $label));
        }

        if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
            throw new DisplayException(sprintf('%s contains an invalid port.', $label));
        }

        $rawHost = strtolower(trim((string) $parts['host'], '[]'));
        // CURLOPT_RESOLVE must pin the exact hostname cURL will connect to.
        // Reject the alternate trailing-dot spelling rather than validating a
        // normalized host and then requesting a differently spelled one.
        if (str_ends_with($rawHost, '.')) {
            throw new DisplayException(sprintf('%s host must not end with a dot.', $label));
        }

        $host = $rawHost;
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new DisplayException(sprintf('%s must resolve to a public network address.', $label));
        }

        $literalIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $literalIp
            ? [$host]
            : $this->resolveHostAddresses($host);

        if ($addresses === []) {
            throw new DisplayException(sprintf('%s host could not be resolved.', $label));
        }

        foreach ($addresses as $address) {
            if (!$this->isPublicAddress($address)) {
                throw new DisplayException(sprintf('%s must resolve only to public network addresses.', $label));
            }
        }

        return [
            'host' => $host,
            'port' => (int) ($parts['port'] ?? 443),
            'addresses' => $this->preferRoutable($addresses),
            'literalIp' => $literalIp,
        ];
    }

    /**
     * Drop address families this host has no route for.
     *
     * Pinning the resolver's answer is what makes the safety check and the
     * connection agree, and it is not optional -- without it a host can answer
     * the check with a public address and rebind to loopback for the fetch.
     * But pinning also takes address *selection* away from the resolver, and
     * the resolver was doing something useful with it: on a machine with AAAA
     * records and no IPv6 route, `getaddrinfo` ordering plus the client's own
     * fallback is what quietly makes IPv4 win. Hand cURL a pinned list and that
     * disappears -- every connection to such a host fails instantly, with a
     * message about being unable to reach a server that is in fact reachable.
     *
     * So the selection has to be done here instead. A family with no route is
     * removed rather than reordered, because reordering only helps if the
     * client walks the whole list, which is exactly the behaviour that varies.
     *
     * Conservative in both directions: an inconclusive probe keeps the address,
     * and if the filter would empty the list it is discarded entirely, so a
     * genuinely unreachable host still fails as a connection error rather than
     * as "could not be resolved".
     *
     * @param array<int, string> $addresses
     *
     * @return array<int, string>
     */
    protected function preferRoutable(array $addresses): array
    {
        $routable = array_values(array_filter(
            $addresses,
            fn (string $address): bool => $this->hasRouteTo($address),
        ));

        return $routable === [] ? $addresses : $routable;
    }

    /**
     * Whether the kernel has any route to this address.
     *
     * A connected UDP socket sends nothing -- `connect(2)` on a datagram socket
     * only fixes the peer -- but it does consult the routing table, so this
     * answers the question without a packet, a handshake or a timeout.
     */
    protected function hasRouteTo(string $address): bool
    {
        $target = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $socket = @stream_socket_client(
            sprintf('udp://%s:443', $target),
            $errorCode,
            $errorMessage,
            1,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveHostAddresses(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        // Some environments do not expose dns_get_record() results for A
        // records even though the system resolver can resolve the host.
        if ($addresses === []) {
            foreach ((array) @gethostbynamel($host) as $address) {
                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicAddress(string $address): bool
    {
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return false;
        }

        $packed = @inet_pton($address);
        if (!is_string($packed)) {
            return false;
        }

        // PHP's NO_RES_RANGE flag does not reject multicast or every
        // documentation/special-purpose range on every supported PHP build.
        $blockedRanges = strlen($packed) === 4
            ? [
                ['192.0.2.0', 24],
                ['198.51.100.0', 24],
                ['203.0.113.0', 24],
                ['224.0.0.0', 4],
                ['240.0.0.0', 4],
            ]
            : [
                ['::ffff:0:0', 96],
                ['64:ff9b:1::', 48],
                ['100::', 64],
                ['2001:db8::', 32],
                ['ff00::', 8],
            ];

        foreach ($blockedRanges as [$network, $prefix]) {
            if ($this->matchesCidr($packed, (string) inet_pton($network), $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function matchesCidr(string $address, string $network, int $prefix): bool
    {
        if ($network === '' || strlen($address) !== strlen($network)) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
