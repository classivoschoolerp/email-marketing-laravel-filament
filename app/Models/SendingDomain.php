<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainStatus;
use App\Models\Concerns\BelongsToCustomer;
use Database\Factories\SendingDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sending domain with DKIM keypair (spec §2.9.7, WP-15). customer_id NULL =
 * global/admin row (§3.13.5). The private key is encrypted at rest; the
 * public key feeds the published DKIM TXT record. Linked delivery servers
 * receive the signing key via SyncDkimToServers (F-2.9.7-007).
 */
class SendingDomain extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<SendingDomainFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['dkim_private_key'];

    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'dkim_private_key' => 'encrypted',
            'dkim_verified' => 'boolean',
            'spf_verified' => 'boolean',
            'dmarc_verified' => 'boolean',
            'mx_verified' => 'boolean',
            // WP-33 §2.9.9 F-2.9.9-002: BIMI is informational and lives
            // outside the WP-15 verified-badge gate.
            'bimi_verified' => 'boolean',
            'bimi_checked_at' => 'datetime',
            // WP-46 §2.9.7 F-2.9.7-009: last per-check BIMI assessment.
            'bimi_findings' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $domain): void {
            $domain->name = mb_strtolower(trim((string) $domain->name));
        });
    }

    public function deliveryServers(): HasMany
    {
        return $this->hasMany(DeliveryServer::class)->withoutGlobalScopes();
    }

    public function isVerified(): bool
    {
        return $this->status === DomainStatus::Verified;
    }

    /** DNS name of the DKIM TXT record: {selector}._domainkey.{domain}. */
    public function dkimRecordName(): string
    {
        return $this->dkim_selector.'._domainkey.'.$this->name;
    }

    /** Base64 DKIM public key (PEM armour + whitespace stripped) for p=. */
    public function dkimPublicKeyBase64(): string
    {
        return (string) preg_replace(
            '/-----(BEGIN|END) PUBLIC KEY-----|\s+/',
            '',
            (string) $this->dkim_public_key,
        );
    }

    /** DNS name of the BIMI TXT record: {selector}._bimi.{domain} (F-2.9.7-009). */
    public function bimiRecordName(): string
    {
        $selector = (string) config('emp-deliverability.bimi.selector', 'default');

        return $selector.'._bimi.'.$this->name;
    }

    /**
     * The BIMI TXT value to publish, built from this domain's own logo/VMC
     * URLs — or an EMPTY STRING when no logo URL is stored. A record sheet
     * must never hand somebody a made-up asset URL to paste into DNS, so the
     * caller renders a "set a logo URL first" placeholder instead.
     */
    public function bimiRecordValue(): string
    {
        $logo = trim((string) $this->bimi_logo_url);

        if ($logo === '') {
            return '';
        }

        $value = 'v=BIMI1; l='.$logo;
        $vmc = trim((string) $this->bimi_vmc_url);

        return $vmc === '' ? $value.';' : $value.'; a='.$vmc.';';
    }

    /** True once a logo URL exists, i.e. a BIMI record can be generated. */
    public function hasBimiLogo(): bool
    {
        return trim((string) $this->bimi_logo_url) !== '';
    }
}
