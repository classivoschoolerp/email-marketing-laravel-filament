<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Dns\DnsResolverInterface;

/**
 * Scripted DNS answers for domain verification tests (WP-15). Bind on
 * DnsResolverInterface — the verifier services pick it up lazily.
 */
final class FakeDnsResolver implements DnsResolverInterface
{
    /**
     * @param  array<string, list<string>>  $txt  host => TXT values
     * @param  array<string, string>  $cname  host => target
     * @param  array<string, list<string>>  $mx  domain => exchanges
     * @param  array<string, list<string>>  $a  host => IPv4 addresses (WP-33 DNSBL lookups)
     */
    public function __construct(
        public array $txt = [],
        public array $cname = [],
        public array $mx = [],
        public array $a = [],
    ) {}

    public function txtRecords(string $host): array
    {
        return $this->txt[strtolower($host)] ?? [];
    }

    public function cnameTarget(string $host): ?string
    {
        return $this->cname[strtolower($host)] ?? null;
    }

    public function mxRecords(string $domain): array
    {
        return $this->mx[strtolower($domain)] ?? [];
    }

    public function aRecords(string $host): array
    {
        return $this->a[strtolower($host)] ?? [];
    }
}
