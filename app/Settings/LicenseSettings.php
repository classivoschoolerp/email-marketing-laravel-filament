<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * License settings (spec §3.25.12, DECISIONS D-09).
 *
 * The key is entered here; validation happens through
 * {@see \App\Services\License\LicenseService} against the configurable
 * endpoint (config/license.php). No endpoint configured = valid self-hosted
 * mode. Status fields below are written by the service, never edited by hand.
 */
class LicenseSettings extends Settings
{
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_UNREACHABLE = 'unreachable';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_UNKNOWN,
        self::STATUS_VALID,
        self::STATUS_INVALID,
        self::STATUS_UNREACHABLE,
    ];

    /** License key — encrypted at rest (F-3.25.12-001). */
    public ?string $license_key;

    // Result of the last validation (F-3.25.12-002)

    /** One of: unknown | valid | invalid | unreachable. */
    public string $status;

    /** License type reported by the server, e.g. "self-hosted", "commercial". */
    public ?string $type;

    /** ISO date (Y-m-d) the license expires; null = perpetual. */
    public ?string $expires_at;

    /**
     * Domains the license is valid for; empty = any.
     *
     * @var list<string>
     */
    public array $allowed_domains;

    /** Renewal / upgrade URL reported by the license server (F-3.25.12-003). */
    public ?string $renewal_url;

    /** ISO-8601 timestamp of the last validation attempt. */
    public ?string $last_checked_at;

    public static function group(): string
    {
        return 'license';
    }

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return [
            'license_key',
        ];
    }
}
