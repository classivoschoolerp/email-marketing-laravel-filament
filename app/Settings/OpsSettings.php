<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Operations & admin health settings (spec §6.6, WP-24): slow-query
 * recording, resource thresholds and their alert channel, backup schedule +
 * retention, worker-registry staleness and the smart-cache toggle.
 */
class OpsSettings extends Settings
{
    // F-6.6-002 — system error notifications in the admin area.
    public bool $error_notifications_enabled;

    /** Minimum severity that raises an admin notification. */
    public string $error_notification_level;

    // F-6.6-003 — slow query recorder.
    public bool $slow_query_logging;

    public int $slow_query_threshold_ms;

    public int $slow_query_retention_days;

    // F-6.6-005 — threshold alerts.
    public int $disk_free_percent_warning;

    public int $memory_percent_warning;

    public int $database_size_mb_warning;

    public bool $threshold_email_enabled;

    public ?string $threshold_email_to;

    // F-6.6-006 — backups.
    public bool $backup_include_uploads;

    public int $backup_retention_days;

    /** Daily backup time, HH:MM (informational — the scheduler owns cadence). */
    public string $backup_time;

    // F-6.6-008 — worker/node registry.
    public int $worker_stale_seconds;

    // F-0.1-016 — smart caching layer.
    public bool $smart_cache_enabled;

    public int $smart_cache_ttl_seconds;

    /** F-0.1-009 — S3-compatible object storage for uploads/exports. */
    public bool $object_storage_enabled;

    public string $object_storage_disk;

    public static function group(): string
    {
        return 'ops';
    }
}
