<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * SPF/DKIM settings (spec §3.25.10).
 *
 * The RSA-2048 keypair is produced by
 * {@see \App\Services\Dkim\GenerateDkimKeypair}; the private key is stored
 * encrypted at rest, the public key feeds the DNS TXT record shown on the
 * settings page and exposed through
 * {@see \App\Services\Settings\Readers\DkimSettingsReader}.
 */
class DkimSettings extends Settings
{
    // Global DKIM switch (F-3.25.10-001)
    public bool $enabled;

    /** DNS selector, e.g. "default" → default._domainkey (F-3.25.10-001). */
    public string $selector;

    /** PEM RSA-2048 private key — encrypted at rest (F-3.25.10-001). */
    public ?string $private_key;

    /** PEM RSA-2048 public key (F-3.25.10-001). */
    public ?string $public_key;

    /** Sign with the global key even when the sending domain has its own (F-3.25.10-002). */
    public bool $force_global_signing;

    public static function group(): string
    {
        return 'dkim';
    }

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return [
            'private_key',
        ];
    }
}
