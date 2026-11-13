<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Services\Dns\DnsResolverInterface;

/**
 * Per-run DNS memoizer for import-time MX validation (spec §2.2.7
 * F-2.2.7-014).
 *
 * A 100k-row import is typically a few thousand distinct domains, and an
 * uncached MX lookup per ROW would blow the per-chunk time budget on its own.
 * This decorator caches every answer for the lifetime of the wrapping
 * {@see ImportEmailValidator}, i.e. one queued chunk, so a domain is resolved
 * at most once per chunk and never longer than the run — which keeps the
 * result fresh enough for a validation pass while making the cost linear in
 * DOMAINS instead of rows.
 */
final class MemoizedDnsResolver implements DnsResolverInterface
{
    /** @var array<string, list<string>> */
    private array $txt = [];

    /** @var array<string, string|null> */
    private array $cname = [];

    /** @var array<string, list<string>> */
    private array $mx = [];

    /** @var array<string, list<string>> */
    private array $a = [];

    public function __construct(private readonly DnsResolverInterface $inner) {}

    public function txtRecords(string $host): array
    {
        return $this->txt[strtolower($host)] ??= $this->inner->txtRecords($host);
    }

    public function cnameTarget(string $host): ?string
    {
        $key = strtolower($host);

        if (! array_key_exists($key, $this->cname)) {
            $this->cname[$key] = $this->inner->cnameTarget($host);
        }

        return $this->cname[$key];
    }

    public function mxRecords(string $domain): array
    {
        return $this->mx[strtolower($domain)] ??= $this->inner->mxRecords($domain);
    }

    public function aRecords(string $host): array
    {
        return $this->a[strtolower($host)] ??= $this->inner->aRecords($host);
    }

    /** Distinct domains resolved so far — asserted by the F-2.2.7-014 test. */
    public function resolvedDomainCount(): int
    {
        return count($this->mx);
    }
}
