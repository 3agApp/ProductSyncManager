<?php

namespace App\Services\Network;

class HostResolver
{
    /**
     * Get every IPv4 and IPv6 address the host name resolves to in DNS.
     *
     * An empty list means the host could not be resolved, which callers must
     * treat as unsafe rather than letting the HTTP client look the name up
     * again on its own.
     *
     * @return array<int, string>
     */
    public function resolve(string $host): array
    {
        $addresses = [];

        // Asked separately: some resolvers fail a combined A and AAAA query
        // outright when the host has no records of one type.
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            $records = @dns_get_record($host, $type);

            foreach ($records === false ? [] : $records as $record) {
                $address = $record[$field] ?? null;

                if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
