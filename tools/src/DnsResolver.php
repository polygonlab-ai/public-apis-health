<?php

declare(strict_types=1);

namespace PolygonLab\PublicApisHealth\Tools;

/**
 * Résolution par le système : adresses IPv4 d'abord, adresses IPv6 seulement si le nom n'a aucune adresse IPv4.
 */
final class DnsResolver implements Resolver
{
    public function resolve(string $host): array
    {
        $addresses = gethostbynamel($host);
        if ($addresses === false) {
            $addresses = [];
        }
        if ($addresses === []) {
            $records = @dns_get_record($host, DNS_AAAA);
            foreach ($records === false ? [] : $records as $record) {
                $address = $record['ipv6'] ?? null;
                if (\is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
