<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * dns_get_record-backed resolver. Failures (NXDOMAIN, timeouts) surface as
 * empty results — verification treats "no record" and "lookup failed" the
 * same way: not verified.
 */
final class NativeDnsResolver implements DnsResolverInterface
{
    public function txtRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);

        if ($records === false) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            // Long TXT values arrive chunked in `entries`; `txt` is the join.
            $values[] = isset($record['entries']) && is_array($record['entries'])
                ? implode('', $record['entries'])
                : (string) ($record['txt'] ?? '');
        }

        return array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
    }

    public function cnameTarget(string $host): ?string
    {
        $records = @dns_get_record($host, DNS_CNAME);

        if ($records === false || $records === []) {
            return null;
        }

        $target = (string) ($records[0]['target'] ?? '');

        return $target === '' ? null : strtolower(rtrim($target, '.'));
    }

    public function mxRecords(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_MX);

        if ($records === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $record): string => strtolower(rtrim((string) ($record['target'] ?? ''), '.')),
            $records,
        ), static fn (string $v): bool => $v !== ''));
    }

    public function aRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_A);

        if ($records === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $record): string => (string) ($record['ip'] ?? ''),
            $records,
        ), static fn (string $v): bool => $v !== ''));
    }
}
