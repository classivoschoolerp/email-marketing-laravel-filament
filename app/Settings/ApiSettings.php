<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * API settings (spec §3.25.7).
 *
 * Consumed by the API module (WP-19) via
 * {@see \App\Services\Settings\Readers\ApiSettingsReader}. The CORS origins
 * are stored here only — config/cors.php stays untouched; WP-19's CORS layer
 * reads the stored origins at request time.
 */
class ApiSettings extends Settings
{
    // Master switch (F-3.25.7-001)
    public bool $enabled;

    // HMAC request signature verification (F-3.25.7-002)
    public bool $require_signature;

    /**
     * IP allowlist — empty means every IP may call the API (F-3.25.7-003).
     * Entries are single IPs or CIDR ranges.
     *
     * @var list<string>
     */
    public array $ip_allowlist;

    /**
     * IP blocklist — always denied, wins over the allowlist (F-3.25.7-003).
     *
     * @var list<string>
     */
    public array $ip_blocklist;

    /** Requests per API key per minute; 0 = unlimited (F-3.25.7-004). */
    public int $rate_limit_per_minute;

    /** Requests per API key per hour; 0 = unlimited (F-3.25.7-004). */
    public int $rate_limit_per_hour;

    // Pagination cap (F-3.25.7-005)
    public int $max_records_per_page;

    /**
     * Allowed CORS origins; '*' allows any origin (F-3.25.7-006).
     *
     * @var list<string>
     */
    public array $cors_origins;

    // Request logging (F-3.25.7-007)
    public bool $request_logging_enabled;

    /** Days to keep API request logs; 0 = keep forever (F-3.25.7-007). */
    public int $request_log_retention_days;

    public static function group(): string
    {
        return 'api';
    }
}
