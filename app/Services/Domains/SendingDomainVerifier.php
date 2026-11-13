<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\SendingDomain;
use App\Services\Dns\DnsResolverInterface;
use App\Services\Dns\NativeDnsResolver;

/**
 * Per-record DNS verification for sending domains (spec §2.9.7 F-2.9.7-003):
 *  - DKIM: TXT at {selector}._domainkey.{domain} carrying the stored public
 *    key (whitespace-insensitive p= comparison)
 *  - SPF: TXT at the domain with v=spf1 including the platform's SPF host
 *  - DMARC: TXT at _dmarc.{domain} declaring v=DMARC1
 *  - MX: any MX present (optional — informational only)
 *
 * Domain status: verified when every record listed in
 * config emp-infra.domains.required_records passes; failed otherwise
 * (pending only before the first check). The resolver is container-swappable
 * (tests bind FakeDnsResolver on DnsResolverInterface).
 */
class SendingDomainVerifier
{
    private readonly DnsResolverInterface $dns;

    public function __construct(
        private readonly SendingDomainRecords $records,
        ?DnsResolverInterface $dns = null,
        private readonly DmarcPolicyReader $dmarc = new DmarcPolicyReader,
    ) {
        $this->dns = $dns
            ?? (app()->bound(DnsResolverInterface::class) ? app(DnsResolverInterface::class) : new NativeDnsResolver);
    }

    /** Re-checks every record, persists flags + badge, returns the domain. */
    public function verify(SendingDomain $domain): SendingDomain
    {
        $results = [
            'dkim' => $this->dkimPasses($domain),
            'spf' => $this->spfPasses($domain),
            'dmarc' => $this->dmarcPasses($domain),
            'mx' => $this->dns->mxRecords($domain->name) !== [],
        ];

        $required = (array) config('emp-infra.domains.required_records', ['dkim', 'spf', 'dmarc']);

        $verified = $required !== [];

        foreach ($required as $record) {
            if (! ($results[$record] ?? false)) {
                $verified = false;
                break;
            }
        }

        $domain->forceFill([
            'dkim_verified' => $results['dkim'],
            'spf_verified' => $results['spf'],
            'dmarc_verified' => $results['dmarc'],
            'mx_verified' => $results['mx'],
            'status' => $verified ? DomainStatus::Verified : DomainStatus::Failed,
            'last_checked_at' => now(),
        ])->save();

        return $domain;
    }

    private function dkimPasses(SendingDomain $domain): bool
    {
        $expected = $domain->dkimPublicKeyBase64();

        if ($expected === '') {
            return false;
        }

        foreach ($this->dns->txtRecords($domain->dkimRecordName()) as $txt) {
            $normalised = (string) preg_replace('/\s+/', '', $txt);

            if (stripos($normalised, 'v=DKIM1') !== false && str_contains($normalised, 'p='.$expected)) {
                return true;
            }
        }

        return false;
    }

    private function spfPasses(SendingDomain $domain): bool
    {
        $include = 'include:'.$this->records->spfHost();

        foreach ($this->dns->txtRecords($domain->name) as $txt) {
            if (stripos($txt, 'v=spf1') === 0 && stripos($txt, $include) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * A DMARC record exists — unchanged WP-15 semantics. Whether that policy
     * is at ENFORCEMENT is a separate question the BIMI checks ask
     * (DmarcPolicyReader::isEnforcing), and it must not silently start
     * failing dmarc_verified for every p=none domain already in the system.
     */
    private function dmarcPasses(SendingDomain $domain): bool
    {
        return $this->dmarc->fromTxt($this->dns->txtRecords('_dmarc.'.$domain->name)) !== null;
    }
}
