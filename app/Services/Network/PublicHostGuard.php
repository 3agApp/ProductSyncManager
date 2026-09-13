<?php

namespace App\Services\Network;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Keeps requests to user supplied hosts off private and reserved networks.
 *
 * Every address a host resolves to is checked, since a request may connect to
 * any of them; one private answer among public ones is enough to refuse.
 */
class PublicHostGuard
{
    /**
     * Ranges to refuse beyond the private subnets Symfony already lists.
     */
    private const array EXTRA_BLOCKED_SUBNETS = [
        '192.0.0.0/24',    // IETF protocol assignments
        '192.88.99.0/24',  // 6to4 relay anycast
        '224.0.0.0/4',     // Multicast
        '64:ff9b::/96',    // NAT64, which embeds an IPv4 address
        '64:ff9b:1::/48',  // Local-use NAT64
        '100::/64',        // Discard-only
        'ff00::/8',        // Multicast
    ];

    public function __construct(private readonly HostResolver $resolver)
    {
        //
    }

    /**
     * Resolve the host, refusing it if any address is not public.
     *
     * @return array<int, string> an empty list when the host did not resolve
     *
     * @throws UnsafeDestinationException
     */
    public function publicAddresses(string $host): array
    {
        // An IP literal is its own answer and is never looked up.
        $literal = trim($host, '[]');

        $addresses = filter_var($literal, FILTER_VALIDATE_IP) !== false
            ? [$literal]
            : $this->resolver->resolve($host);

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw new UnsafeDestinationException("The host {$host} resolves to the non-public address {$address}.");
            }
        }

        return $addresses;
    }

    /**
     * Determine if the address is on the public internet.
     */
    public function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && ! IpUtils::checkIp($address, [...IpUtils::PRIVATE_SUBNETS, ...self::EXTRA_BLOCKED_SUBNETS]);
    }
}
