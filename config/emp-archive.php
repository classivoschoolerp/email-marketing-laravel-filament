<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Archival / cold storage for old logs (spec §4.6 F-4.6-008)
|--------------------------------------------------------------------------
| `logs:prune` (§0.6 F-0.6-014) enforces the retention windows by DELETING
| rows. On a large install that is the right thing for the hot tables and the
| wrong thing for the record: deliverability disputes, chargebacks and
| regulator requests all arrive after the window has closed.
|
| With archiving on, every batch `logs:prune` is about to delete is first
| streamed to a gzipped NDJSON file on the configured disk and indexed in
| `log_archives`, then deleted. The two steps are ordered so a crash can only
| ever leave a DUPLICATE archive, never a hole: nothing is deleted until its
| bytes are on the disk.
|
| `disk` is any Laravel filesystem disk, so cold storage means S3/Glacier in
| production and the local disk in dev — the code does not care which.
| Restore with `logs:restore-archive {id}`.
*/

return [

    'enabled' => (bool) env('EMP_LOG_ARCHIVE_ENABLED', false),

    /** Filesystem disk that holds the archives. */
    'disk' => env('EMP_LOG_ARCHIVE_DISK', 'local'),

    /** Path prefix inside the disk. */
    'path' => env('EMP_LOG_ARCHIVE_PATH', 'log-archives'),

    /**
     * Rows read (and written) per batch. The prune command deletes in the
     * same batches, so this also bounds the delete transaction.
     */
    'chunk' => (int) env('EMP_LOG_ARCHIVE_CHUNK', 5000),

    /**
     * Tables that may be archived. A table not listed here is pruned the old
     * way — an allow-list, so switching archiving on can never start writing
     * an unexpected table's contents to object storage.
     */
    'tables' => [
        'campaign_delivery_logs',
        'campaign_track_opens',
        'campaign_track_clicks',
        'campaign_track_unsubscribes',
        'login_logs',
        'impersonation_logs',
        'consent_events',
    ],

];
