<?php

declare(strict_types=1);

namespace App\Services\Geo;

/**
 * GeoIP provider contract (spec §3.23 F-3.23-001, D-10) — same shape as the
 * WP-15 DnsResolverInterface / WP-14 sending-driver contracts so vendors are
 * added through {@see GeoProviderRegistry} without a core change (§6.1).
 */
interface IpLocationProvider
{
    /** Stable key used in settings + the registry. */
    public function key(): string;

    /** Human label for select options. */
    public function label(): string;

    /**
     * True when the provider has everything it needs (database file present,
     * token configured, …). A misconfigured provider must report false rather
     * than silently returning empty lookups.
     */
    public function configured(): bool;

    /**
     * Resolve an IPv4/IPv6 address.
     *
     * @throws \RuntimeException when the provider is unusable (missing
     *                           database/credentials) — callers that must not
     *                           fail should check {@see configured()} first.
     */
    public function locate(string $ip): ?GeoLocation;
}
