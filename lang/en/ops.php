<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Operations, location data & deployment (WP-24)
|--------------------------------------------------------------------------
| Spec §6.6 (operations & admin health), §3.23 (countries/zones managers and
| the GeoIP import/update commands) and the §0.1 cache/CPU rows.
*/

return [

    'navigation' => [
        'location' => 'Localization',
    ],

    /* ---------------------------------------------------------------- *
     |  §3.23 F-3.23-004 countries manager
     * ---------------------------------------------------------------- */
    'countries' => [
        'label' => 'Country',
        'plural_label' => 'Countries',
        'section' => 'Country',
        'fields' => [
            'code' => 'ISO code',
            'name' => 'Name',
            'code3' => 'ISO alpha-3',
            'numeric_code' => 'ISO numeric',
            'phone_prefix' => 'Phone prefix',
            'active' => 'Active',
            'zones_count' => 'Zones',
        ],
        'help' => [
            'code' => 'ISO 3166-1 alpha-2, e.g. US, DE, MA.',
            'active' => 'Inactive countries are hidden from address selects and the API.',
        ],
        'actions' => [
            'import_builtin' => 'Import built-in list',
            'import_builtin_help' => 'Imports the bundled ISO 3166-1 list. Existing rows are updated, never duplicated.',
            'import_file' => 'Import file',
            'import_file_label' => 'CSV or JSON file',
            'import_file_help' => 'Columns: code, name, code3, numeric_code, phone_prefix, active.',
        ],
    ],

    /* ---------------------------------------------------------------- *
     |  §3.23 F-3.23-005 zones manager
     * ---------------------------------------------------------------- */
    'zones' => [
        'label' => 'Zone',
        'plural_label' => 'Zones / states',
        'section' => 'Zone',
        'fields' => [
            'country' => 'Country',
            'code' => 'Code',
            'name' => 'Name',
            'type' => 'Type',
            'active' => 'Active',
        ],
        'help' => [
            'code' => 'ISO 3166-2 subdivision code without the country prefix, e.g. CA for US-CA.',
        ],
        'actions' => [
            'import_builtin' => 'Import built-in zones',
            'import_builtin_help' => 'Imports the bundled US, CA, AU and IN subdivisions (countries are imported first).',
            'import_file' => 'Import file',
            'import_file_label' => 'CSV or JSON file',
            'import_file_help' => 'Columns: country_code, code, name, type, active.',
        ],
    ],

    'geo' => [
        'imported_countries' => 'Imported :count countries.',
        'imported_zones' => 'Imported :count zones.',
        'import_failed' => 'Import failed: :error',
        'file_missing' => 'File not found: :path',
        'auto_update_disabled' => 'MaxMind auto-update is disabled — nothing to do.',
        'database_updated' => 'MaxMind database updated: :path',
    ],

    'files' => [
        'open_basedir_denied' => 'PHP open_basedir does not allow access to :path.',
    ],

    /* ---------------------------------------------------------------- *
     |  §6.6 F-6.6-001 operations dashboard
     * ---------------------------------------------------------------- */
    'dashboard' => [
        'navigation_label' => 'Operations',
        'title' => 'Operations & health',
        'overview' => 'System overview',
        'driver' => ':name driver',
        'queues' => 'Queues',
        'pending' => 'Pending jobs',
        'failed' => 'Failed jobs',
        'alive_workers' => 'Live workers',
        'capacity' => 'Capacity',
        'cores' => 'Detected CPU cores: :cores (source: :source)',
        'suggested_workers' => 'Suggested worker layout: :sending sending + :general general + 1 scheduler = :total processes.',
        'cache_layer' => 'Smart cache: :state (driver: :driver).',
        'cache_not_taggable' => 'This driver has no tag support — buckets cannot be flushed selectively.',
        'enabled' => 'enabled',
        'disabled' => 'disabled',
        'cron' => 'Recent scheduled runs',
        'cron_note' => 'Every §0.6 job records a row here. An empty table means the system cron entry is not running.',
        'job' => 'Job',
        'status' => 'Status',
        'processed' => 'Processed',
        'started_at' => 'Started',
    ],

    /* ---------------------------------------------------------------- *
     |  §6.6 F-6.6-002 error notifications
     * ---------------------------------------------------------------- */
    'errors' => [
        'heading' => 'System errors',
        'disabled' => 'Error notifications are switched off in the operations settings.',
        'new_since_last_scan' => ':count new entries since the last scan.',
        'none_new' => 'No new :level or higher entries.',
        'empty' => 'No matching log entries.',
        'view_log' => 'Open the error log',
        'notification_title' => 'New :level entry in the application log',
        'notification_body_many' => ':message (+:count more)',
        'notification_action' => 'View log',
        'scanned' => 'Announced :count new log entries.',
    ],

    /* ---------------------------------------------------------------- *
     |  §6.6 F-6.6-005 resource thresholds
     * ---------------------------------------------------------------- */
    'health' => [
        'heading' => 'Resources',
        'all_ok' => 'All monitored resources are within their thresholds.',
        'breaches' => ':count threshold breach(es).',
        'metrics' => [
            'disk' => 'Free disk',
            'memory' => 'Memory used',
            'database' => 'Database size',
        ],
        'disk_unavailable' => 'The host does not report disk space to PHP.',
        'disk_detail' => ':free free of :total',
        'memory_system_detail' => ':used of :total used (host)',
        'memory_process_detail' => ':used of :limit used (this PHP process — the host total is not readable here)',
        'memory_unlimited' => 'memory_limit is unlimited; PHP peak usage :used',
        'database_unavailable' => 'This database driver does not expose a cheap size query.',
        'database_detail' => 'Warning threshold: :threshold MB',
        'alert_title' => 'Threshold alert: :metric',
        'alert_body' => 'Current value :value, configured threshold :threshold.',
        'alert_intro' => 'A monitored resource on :app crossed its configured threshold.',
        'alert_current' => 'Current value: :value',
        'alert_threshold' => 'Threshold: :threshold',
        'alert_footer' => 'Adjust thresholds under Settings → Operations.',
        'alerts_raised' => 'Raised :count threshold alert(s).',
    ],

    /* ---------------------------------------------------------------- *
     |  §6.6 F-6.6-003 performance panel
     * ---------------------------------------------------------------- */
    'performance' => [
        'navigation_label' => 'Performance',
        'title' => 'Performance monitoring',
        'slow_queries' => 'Slow queries',
        'slow_queries_on' => 'Recording queries slower than :ms ms.',
        'slow_queries_off' => 'Slow-query recording is off — enable it under Settings → Operations. The listener is not installed while it is off, so there is no overhead.',
        'recorded_total' => 'Recorded (all time)',
        'recorded_24h' => 'Recorded (24h)',
        'slowest' => 'Slowest',
        'sql' => 'SQL',
        'hits' => 'Hits',
        'max_ms' => 'Max ms',
        'avg_ms' => 'Avg ms',
        'runtime' => 'Runtime',
        'cpu_cores' => 'CPU cores',
        'suggested' => ':total suggested processes',
        'cache_hit_rate' => 'Cache hit rate',
        'cache_unsupported' => 'The :driver cache driver does not expose hit/miss counters.',
        'worker_note' => 'Processes that have reported in via queue events or queue:heartbeat.',
        'clear_slow_queries' => 'Clear recorded queries',
        'cleared' => 'Deleted :count recorded queries.',
        'pruned' => 'Pruned :count slow-query rows.',
    ],

    /* ---------------------------------------------------------------- *
     |  §6.6 F-6.6-004 index health
     * ---------------------------------------------------------------- */
    'index_health' => [
        'heading' => 'Index health',
        'driver' => 'Database driver: :driver',
        'no_missing' => 'All expected indexes are present.',
        'missing_heading' => 'Missing indexes — run these statements:',
        'duplicates_heading' => 'Redundant indexes (one is a prefix of another):',
        'duplicate_line' => ':table.:index is redundant with :other',
        'unused_heading' => 'Never-used indexes:',
        'no_unused' => 'No never-used indexes reported.',
        'unused_unavailable' => 'Unused-index detection unavailable: :note',
        'requires_mysql' => 'requires MySQL/MariaDB with performance_schema enabled (D-03).',
        'performance_schema_off' => 'performance_schema is not readable: :error',
    ],

    /* ---------------------------------------------------------------- *
     |  §6.6 F-6.6-006 backups
     * ---------------------------------------------------------------- */
    'backups' => [
        'navigation_label' => 'Backups',
        'title' => 'Backups',
        'schedule_heading' => 'Schedule & retention',
        'directory' => 'Directory',
        'retention' => 'Retention',
        'retention_forever' => 'Keep forever',
        'retention_days' => ':days days',
        'uploads' => 'Uploads archive',
        'total_size' => 'Total size',
        'schedule_note' => 'backups:run is scheduled daily by the §0.6 scheduler and can be disabled per job under Settings → Cron.',
        'zip_unavailable' => 'PHP ext-zip is not available, so uploads cannot be archived. The database backup still runs.',
        'zip_open_failed' => 'Could not create the archive at :path.',
        'files_heading' => 'Stored backups',
        'empty' => 'No backups yet. Run one from the button above or wait for the nightly job.',
        'file' => 'File',
        'kind' => 'Kind',
        'size' => 'Size',
        'modified' => 'Created',
        'actions' => 'Actions',
        'download' => 'Download',
        'delete' => 'Delete',
        'confirm_delete' => 'Delete this backup file permanently?',
        'kinds' => [
            'database' => 'Database',
            'uploads' => 'Uploads',
        ],
        'not_found' => 'Backup file not found.',
        'deleted' => 'Deleted :name.',
        'run_now' => 'Run backup now',
        'run_finished' => 'Backup finished.',
        'run_failed' => 'Backup failed — check the cron run history for the error.',
        'prune_now' => 'Prune old backups',
        'pruned' => 'Deleted :count expired backup(s).',
        'restore' => [
            'heading' => 'How to restore',
            'intro' => 'Restore is intentionally manual: an automated button that drops a live database is how installations get destroyed. Run these on the server, in order.',
            'step_stop' => '1. Stop traffic and workers',
            'step_database' => '2. Restore the database',
            'step_uploads' => '3. Restore uploads',
            'step_start' => '4. Migrate and bring everything back up',
            'unsupported_driver' => 'No restore recipe for driver ":driver" — restore it with your vendor tooling.',
        ],
    ],

    /* ---------------------------------------------------------------- *
     |  §6.6 F-6.6-008/009 worker registry + drain
     * ---------------------------------------------------------------- */
    'workers' => [
        'navigation_label' => 'Worker nodes',
        'title' => 'Worker nodes',
        'heading' => 'Registered processes',
        'description' => 'One row per worker process (node + pid), written by queue events and queue:heartbeat.',
        'empty' => 'No worker has reported in yet. Start a queue worker, or add the queue:heartbeat process to Supervisor.',
        'node' => 'Node',
        'pid' => 'PID',
        'queues' => 'Queues',
        'current_job' => 'Current job',
        'processed' => 'Processed / failed',
        'last_beat' => 'Last heartbeat',
        'state' => 'State',
        'alive' => 'alive',
        'stale' => 'stale',
        'setup_note' => 'Add "php artisan queue:heartbeat --queues=..." next to each queue:work program so idle nodes stay visible between jobs.',
        'restart' => 'Restart workers',
        'restart_help' => 'Broadcasts queue:restart. Each worker finishes its current job and exits; your process supervisor restarts it with fresh code.',
        'restart_sent' => 'Restart signal broadcast to all workers.',
        'drain' => 'Drain workers',
        'drain_help' => 'Raises the drain flag. Heartbeat processes exit and workers stop taking new work — use this before maintenance.',
        'drain_set' => 'Drain flag raised.',
        'cancel_drain' => 'Cancel drain',
        'drain_cleared' => 'Drain flag cleared.',
        'drain_active' => 'Drain is active — workers are shutting down and will not pick up new jobs.',
        'drain_meta' => 'raised at :at by :by',
        'prune' => 'Prune stale rows',
        'pruned' => 'Removed :count stale worker row(s).',
        'beat' => 'Heartbeat written for :node (pid :pid).',
        'draining_exit' => 'Drain flag is set — exiting so the supervisor can restart this process.',
    ],

    /* ---------------------------------------------------------------- *
     |  §0.1 F-0.1-016 smart cache
     * ---------------------------------------------------------------- */
    'cache' => [
        'flushed' => 'Smart cache buckets flushed.',
        'flush_unsupported' => 'The :driver cache driver has no tag support — buckets expire on their TTL instead.',
        'warmed' => 'Warmed :count cache entries.',
        'warmed_entry' => '  :key = :value',
    ],

    /* ---------------------------------------------------------------- *
     |  §6.9 F-6.9-006 demo data
     * ---------------------------------------------------------------- */
    'demo' => [
        'exists' => 'Demo data already exists for :email — pass --fresh to rebuild it.',
        'created' => 'Demo dataset created for :email — :subscribers subscribers and the campaign ":campaign".',
        'password_note' => 'Demo customer password: demo-password-1234 (change or delete this account before going live).',
    ],

    /* ---------------------------------------------------------------- *
     |  §4.6 F-4.6-008 log archival / cold storage
     * ---------------------------------------------------------------- */
    'archive' => [
        'empty' => 'No log archives have been written yet.',
        'not_found' => 'No log archive with id :id.',
        'restored' => 'Restored :rows row(s) into :table.',
    ],

    /* ---------------------------------------------------------------- *
     |  §0.1 F-0.1-008 search engines
     * ---------------------------------------------------------------- */
    'search' => [
        'no_engine' => 'No search engine is configured (driver: :driver) — subscriber search uses the database.',
        'reindexed' => 'Indexed :count subscriber(s) into :index.',
        'queued' => 'Queued a reindex of :index.',
    ],

    /* ---------------------------------------------------------------- *
     |  §4.6 F-4.6-007 reporting read replica
     * ---------------------------------------------------------------- */
    'replica' => [
        'heartbeat_written' => 'Replication heartbeat stamped at :at.',
        'heartbeat_missing_table' => 'Table :table does not exist — run `php artisan migrate` on the primary first.',
    ],

];
