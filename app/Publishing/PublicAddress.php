<?php

namespace App\Publishing;

use Symfony\Component\HttpFoundation\IpUtils;

class PublicAddress
{
    /**
     * Resolve every address and refuse the host if any is not public.
     *
     * @return list<string>
     */
    public function addresses(string $address): array
    {
        $parts = parse_url($address);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || ! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            return [];
        }

        $addresses = $this->resolve($host);

        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false
                || IpUtils::isPrivateIp($ip)
                || IpUtils::checkIp($ip, ['224.0.0.0/4', 'ff00::/8', 'fec0::/10'])) {
                return [];
            }
        }

        return $addresses;
    }

    /**
     * Resolve both address families, without caching between requests.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];

        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        return array_values(array_unique($addresses));
    }
}
