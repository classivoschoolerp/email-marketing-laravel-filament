<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\OpsSettings;

/**
 * Typed accessors for the `ops` settings group (spec §6.6, WP-24).
 */
final readonly class OpsSettingsReader
{
    public function __construct(private OpsSettings $settings) {}

    public function errorNotificationsEnabled(): bool
    {
        return $this->settings->error_notifications_enabled;
    }

    public function errorNotificationLevel(): string
    {
        return strtoupper($this->settings->error_notification_level);
    }

    public function slowQueryLogging(): bool
    {
        return $this->settings->slow_query_logging;
    }

    public function slowQueryThresholdMs(): int
    {
        return max(1, $this->settings->slow_query_threshold_ms);
    }

    public function slowQueryRetentionDays(): int
    {
        return max(0, $this->settings->slow_query_retention_days);
    }

    public function diskFreePercentWarning(): int
    {
        return $this->settings->disk_free_percent_warning;
    }

    public function memoryPercentWarning(): int
    {
        return $this->settings->memory_percent_warning;
    }

    public function databaseSizeMbWarning(): int
    {
        return $this->settings->database_size_mb_warning;
    }

    public function thresholdEmailEnabled(): bool
    {
        return $this->settings->threshold_email_enabled;
    }

    public function thresholdEmailTo(): ?string
    {
        return $this->settings->threshold_email_to;
    }

    public function backupIncludeUploads(): bool
    {
        return $this->settings->backup_include_uploads;
    }

    public function backupRetentionDays(): int
    {
        return max(0, $this->settings->backup_retention_days);
    }

    public function backupTime(): string
    {
        return $this->settings->backup_time;
    }

    public function workerStaleSeconds(): int
    {
        return max(10, $this->settings->worker_stale_seconds);
    }

    public function smartCacheEnabled(): bool
    {
        return $this->settings->smart_cache_enabled;
    }

    public function smartCacheTtlSeconds(): int
    {
        return max(0, $this->settings->smart_cache_ttl_seconds);
    }

    public function objectStorageEnabled(): bool
    {
        return $this->settings->object_storage_enabled;
    }

    public function objectStorageDisk(): string
    {
        return $this->settings->object_storage_disk;
    }
}
