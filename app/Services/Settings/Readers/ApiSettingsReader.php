<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\ApiSettings;

/**
 * Typed accessors for the `api` settings group (spec §3.25.7).
 * Consumed by the API module (WP-19).
 */
final readonly class ApiSettingsReader
{
    public function __construct(private ApiSettings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->enabled;
    }

    public function requireSignature(): bool
    {
        return $this->settings->require_signature;
    }

    /**
     * @return list<string>
     */
    public function ipAllowlist(): array
    {
        return $this->settings->ip_allowlist;
    }

    /**
     * @return list<string>
     */
    public function ipBlocklist(): array
    {
        return $this->settings->ip_blocklist;
    }

    /**
     * 0 = unlimited.
     */
    public function rateLimitPerMinute(): int
    {
        return $this->settings->rate_limit_per_minute;
    }

    /**
     * 0 = unlimited.
     */
    public function rateLimitPerHour(): int
    {
        return $this->settings->rate_limit_per_hour;
    }

    public function maxRecordsPerPage(): int
    {
        return $this->settings->max_records_per_page;
    }

    /**
     * Stored CORS origins — applied by the API layer at request time;
     * config/cors.php is deliberately left untouched.
     *
     * @return list<string>
     */
    public function corsOrigins(): array
    {
        return $this->settings->cors_origins;
    }

    public function requestLoggingEnabled(): bool
    {
        return $this->settings->request_logging_enabled;
    }

    /**
     * 0 = keep forever.
     */
    public function requestLogRetentionDays(): int
    {
        return $this->settings->request_log_retention_days;
    }
}
