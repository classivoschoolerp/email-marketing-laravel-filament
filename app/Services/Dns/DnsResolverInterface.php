<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * DNS lookup seam for domain verification (spec §2.9.7/§2.9.8, WP-15).
 * Production: NativeDnsResolver (dns_get_record). Tests bind FakeDnsResolver
 * on this interface — the verifier services resolve it lazily so no service
 * provider registration is needed.
 */
interface DnsResolverInterface
{
    /** All TXT record strings published at the host (chunks joined). */
    public function txtRecords(string $host): array;

    /** CNAME target of the host, or null when none exists. */
    public function cnameTarget(string $host): ?string;

    /** MX exchange hostnames for the domain. */
    public function mxRecords(string $domain): array;

    /**
     * IPv4 addresses published at the host. Added by WP-33 for DNSBL
     * lookups (spec §2.9.9 F-2.9.9-003), where a listing IS an A record at
     * `{reversed-ip}.{zone}` — an empty result means "not listed".
     *
     * @return list<string>
     */
    public function aRecords(string $host): array;
}
