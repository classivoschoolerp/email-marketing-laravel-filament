<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\TrackingDomain;
use App\Services\Dns\DnsResolverInterface;
use App\Services\Dns\NativeDnsResolver;

/**
 * CNAME verification for tracking domains (spec §2.9.8 F-2.9.8-003): the
 * domain must CNAME to the platform's tracking target
 * (TrackingDomain::cnameTarget()). SSL provisioning stays a placeholder
 * column — automatic HTTPS is P2 (F-2.9.8-005).
 */
class TrackingDomainVerifier
{
    private readonly DnsResolverInterface $dns;

    public function __construct(?DnsResolverInterface $dns = null)
    {
        $this->dns = $dns
            ?? (app()->bound(DnsResolverInterface::class) ? app(DnsResolverInterface::class) : new NativeDnsResolver);
    }

    public function verify(TrackingDomain $domain): TrackingDomain
    {
        $target = $this->dns->cnameTarget($domain->name);
        $verified = $target !== null
            && strtolower($target) === strtolower(TrackingDomain::cnameTarget());

        $domain->forceFill([
            'verified' => $verified,
            'status' => $verified ? DomainStatus::Verified : DomainStatus::Failed,
            'last_checked_at' => now(),
        ])->save();

        return $domain;
    }
}
