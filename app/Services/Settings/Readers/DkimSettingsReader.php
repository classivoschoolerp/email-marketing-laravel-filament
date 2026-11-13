<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\DkimSettings;

/**
 * Typed accessors for the `dkim` settings group (spec §3.25.10).
 */
final readonly class DkimSettingsReader
{
    public function __construct(private DkimSettings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->enabled;
    }

    public function selector(): string
    {
        return $this->settings->selector;
    }

    public function privateKey(): ?string
    {
        return $this->settings->private_key;
    }

    public function publicKey(): ?string
    {
        return $this->settings->public_key;
    }

    public function forceGlobalSigning(): bool
    {
        return $this->settings->force_global_signing;
    }

    public function keypairConfigured(): bool
    {
        return $this->settings->private_key !== null && $this->settings->public_key !== null;
    }

    /**
     * DNS record host for the DKIM TXT record, e.g. "default._domainkey".
     */
    public function txtRecordHost(): string
    {
        return $this->settings->selector.'._domainkey';
    }

    /**
     * DKIM TXT record value: "v=DKIM1; k=rsa; p=<base64 DER public key>".
     * Null until a keypair has been generated.
     */
    public function txtRecordValue(): ?string
    {
        $publicKey = $this->settings->public_key;

        if ($publicKey === null) {
            return null;
        }

        $base64 = preg_replace(
            '/-----(BEGIN|END) PUBLIC KEY-----|\s+/',
            '',
            $publicKey,
        );

        return 'v=DKIM1; k=rsa; p='.$base64;
    }

    /**
     * Full DNS TXT record string shown to the administrator.
     */
    public function dnsTxtRecord(): ?string
    {
        $value = $this->txtRecordValue();

        return $value === null
            ? null
            : $this->txtRecordHost().' IN TXT "'.$value.'"';
    }

    /**
     * SPF record recommendation for sending domains (F-3.25.10-003).
     */
    public function spfRecommendation(): string
    {
        return 'v=spf1 a mx include:'.$this->applicationHost().' ~all';
    }

    public function applicationHost(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }
}
