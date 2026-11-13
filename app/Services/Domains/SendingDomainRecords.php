<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Models\SendingDomain;

/**
 * Builds the DNS records a sending domain must publish (spec §2.9.7
 * F-2.9.7-002): DKIM TXT at {selector}._domainkey, SPF TXT include, DMARC
 * TXT policy, MX (optional, informational) and — since WP-46 (F-2.9.7-009) —
 * BIMI TXT at {selector}._bimi. Rendered copy-paste-ready in the Filament
 * view pages (F-2.9.7-005).
 *
 * The BIMI entry is `required: false` on purpose: BIMI is advisory and stays
 * out of the verified-badge gate (config emp-infra.domains.required_records).
 * Its `value` is an empty string until the domain has a logo URL — see
 * SendingDomain::bimiRecordValue().
 */
class SendingDomainRecords
{
    /**
     * @return array<string, array{type: string, name: string, value: string, required: bool}>
     */
    public function for(SendingDomain $domain): array
    {
        return [
            'dkim' => [
                'type' => 'TXT',
                'name' => $domain->dkimRecordName(),
                'value' => 'v=DKIM1; k=rsa; p='.$domain->dkimPublicKeyBase64(),
                'required' => true,
            ],
            'spf' => [
                'type' => 'TXT',
                'name' => $domain->name,
                'value' => 'v=spf1 include:'.$this->spfHost().' ~all',
                'required' => true,
            ],
            'dmarc' => [
                'type' => 'TXT',
                'name' => '_dmarc.'.$domain->name,
                'value' => trim((string) config('emp-infra.domains.dmarc_policy', 'v=DMARC1; p=none;'))
                    .' rua=mailto:postmaster@'.$domain->name,
                'required' => true,
            ],
            'mx' => [
                'type' => 'MX',
                'name' => $domain->name,
                'value' => '10 '.$this->spfHost(),
                'required' => false,
            ],
            'bimi' => [
                'type' => 'TXT',
                'name' => $domain->bimiRecordName(),
                'value' => $domain->bimiRecordValue(),
                'required' => false,
            ],
        ];
    }

    /** Host customers must include in SPF (config override or app.url host). */
    public function spfHost(): string
    {
        $configured = config('emp-infra.domains.spf_host');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }
}
