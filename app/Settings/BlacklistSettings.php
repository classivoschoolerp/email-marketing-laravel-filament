<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Email blacklist settings (spec §3.25.5).
 *
 * Consumed by the blacklist module (WP-16) via
 * {@see \App\Services\Settings\Readers\BlacklistSettingsReader}.
 */
class BlacklistSettings extends Settings
{
    public const APPLY_AT_IMPORT = 'import';

    public const APPLY_AT_SEND = 'send';

    public const APPLY_AT_BOTH = 'both';

    /** @var list<string> */
    public const APPLY_AT = [
        self::APPLY_AT_IMPORT,
        self::APPLY_AT_SEND,
        self::APPLY_AT_BOTH,
    ];

    // Global blacklist switch (F-3.25.5-001)
    public bool $enabled;

    /**
     * Regex rules that auto-blacklist matching addresses (F-3.25.5-002).
     * Each entry is a complete, valid PCRE pattern including delimiters.
     *
     * @var list<string>
     */
    public array $regex_rules;

    /** One of: import | send | both (F-3.25.5-003). */
    public string $apply_at;

    /** Shared global blacklist vs per-customer isolation (F-3.25.5-004). */
    public bool $shared_across_customers;

    public static function group(): string
    {
        return 'blacklist';
    }
}
