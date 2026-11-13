<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Live upgrade manager (spec §6.2, WP-41)
|--------------------------------------------------------------------------
| Every string the upgrade manager renders. docs/ARCHITECTURE.md: no hardcoded English
| in views, Filament labels, command output or mail.
*/

return [

    'nav_group' => 'System Health',
    'navigation_label' => 'Upgrades',
    'title' => 'Upgrade manager',
    'subheading' => 'Check for new releases, review the changelog and apply an upgrade with a verified, backed-up, reversible run.',

    'version' => [
        'current' => 'Installed version',
        'latest' => 'Latest available',
        'channel' => 'Release channel',
        'checked_at' => 'Last checked',
        'never' => 'Never',
        'released_at' => 'Released',
        'size' => 'Download size',
        'mandatory' => 'Marked mandatory by the publisher',
    ],

    'status' => [
        'disabled' => 'No release feed configured',
        'unknown' => 'Release feed unreachable',
        'current' => 'Up to date',
        'available' => 'Upgrade available',
    ],

    'release' => [
        'no_endpoint' => 'No release feed is configured. Set EMP_UPGRADE_ENDPOINT to enable upgrade checks.',
        'unreachable' => 'The release feed could not be reached. Your installation is unaffected.',
        'unparsable' => 'The release feed returned a document this installation could not read.',
        'changelog' => 'Changelog',
        'no_changelog' => 'The publisher did not include a changelog for this release.',
        'not_installable' => 'This release was announced without a download URL or checksum, so it cannot be installed by one click. Follow the publisher’s manual instructions.',
    ],

    'actions' => [
        'check' => 'Check for updates',
        'dry_run' => 'Dry run',
        'upgrade' => 'Upgrade now',
        'rollback' => 'Roll back files',
        'view_run' => 'Details',
    ],

    'confirm' => [
        'heading' => 'This overwrites the running application',
        'body' => 'The upgrade replaces application source files and runs database migrations against your live data. A full backup is taken first and every replaced file is snapshotted, but migrations are NOT reversed automatically. Read docs/upgrades.md before continuing.',
        'field' => 'Type :phrase to confirm',
        'placeholder' => 'Type the confirmation phrase',
    ],

    'dry_run' => [
        'badge' => 'Dry run',
        'explanation' => 'A dry run downloads the release, verifies its checksum and reports exactly which files would be added, replaced and skipped. It writes nothing to the installation and runs no migrations.',
    ],

    'checks' => [
        'heading' => 'Pre-upgrade checks',
        'blocking' => 'Blocking',
        'advisory' => 'Advisory',
        'pass' => 'Pass',
        'fail' => 'Fail',
        'warning' => 'Warning',

        'disk_space' => [
            'label' => 'Disk space',
            'detail' => ':free MB free, :required MB required',
            'unknown' => 'Free disk space could not be determined on this host.',
        ],
        'php_version' => [
            'label' => 'PHP version',
            'detail' => 'PHP :current installed, :required or newer required',
        ],
        'database' => [
            'label' => 'Database connectivity',
            'detail' => 'Connected (:driver)',
            'failed' => 'The database is not reachable: :error',
        ],
        'pending_migrations' => [
            'label' => 'Pending migrations',
            'none' => 'No pending migrations — the schema matches this release.',
            'detail' => ':count migration(s) have not been run yet (first: :first). Run them before upgrading.',
            'failed' => 'Migration status could not be read: :error',
            'no_repository' => 'The migrations table does not exist — this installation has never been migrated.',
        ],
        'core_version' => [
            'label' => 'Version compatibility',
            'detail' => 'Upgrading :current → :release',
            'no_release' => 'No release selected; installed version is :current.',
            'not_newer' => 'Release :release is not newer than the installed version :current.',
            'unsatisfied' => 'The installed version :current does not satisfy this release’s requirement ":constraint". Upgrade to an intermediate release first.',
            'not_installable' => 'Release :release was published without a usable download URL and checksum.',
        ],
        'target_writable' => [
            'label' => 'Application directory writable',
            'detail' => 'Writable (:path)',
            'failed' => 'The application directory is not writable: :path',
        ],
        'backup_directory' => [
            'label' => 'Backup directory writable',
            'detail' => 'Writable (:path)',
            'failed' => 'Backups cannot be written to :path — the pre-upgrade backup would fail.',
        ],
        'zip_extension' => [
            'label' => 'PHP zip extension',
            'detail' => 'ext-zip is required to read a release archive.',
        ],
        'maintenance' => [
            'label' => 'Maintenance mode',
            'detail' => 'Maintenance mode can be enabled for the duration of the upgrade.',
            'disabled' => 'Maintenance mode is disabled in configuration — the upgrade will run with the site live.',
            'unwritable' => 'storage/framework is not writable, so maintenance mode cannot be enabled. The upgrade will run with the site live.',
        ],
    ],

    'steps' => [
        'heading' => 'Steps',
        'preflight_ok' => 'All :count pre-upgrade checks passed.',
        'download_detail' => 'Downloaded :bytes bytes.',
        'verify_detail' => 'Checksum verified (:algo). :files file(s) in package, :protected protected path(s) will be skipped.',
        'dry_run_skipped' => 'Skipped — dry run.',
        'backup_detail' => 'Database :database and uploads :uploads.',
        'backup_database_only' => 'Database :database (no uploads archive was produced).',
        'maintenance_on' => 'Maintenance mode enabled.',
        'maintenance_unavailable' => 'Maintenance mode could not be enabled; continuing with the site live.',
        'apply_detail' => ':added file(s) added, :replaced replaced, :protected protected path(s) skipped.',
        'migrate_detail' => 'Migrations applied. :output',
        'caches_detail' => 'Ran: :commands',
        'maintenance_off' => 'Maintenance mode lifted.',
        'maintenance_stuck' => 'Maintenance mode could not be lifted automatically — run "php artisan up".',
        'cleanup_detail' => 'Download removed. Rollback snapshot kept at :snapshot',

        'labels' => [
            'preflight' => 'Pre-flight checks',
            'download' => 'Download release',
            'verify_checksum' => 'Verify checksum',
            'backup' => 'Pre-upgrade backup',
            'maintenance_on' => 'Enable maintenance mode',
            'apply_files' => 'Apply files',
            'migrate' => 'Run migrations',
            'clear_caches' => 'Clear caches',
            'maintenance_off' => 'Lift maintenance mode',
            'cleanup' => 'Clean up',
        ],

        'status' => [
            'pending' => 'Pending',
            'ok' => 'Done',
            'warning' => 'Warning',
            'failed' => 'Failed',
            'skipped' => 'Skipped',
        ],
    ],

    'notes' => [
        'dry_run' => 'Dry run complete: :add file(s) would be added, :replace replaced, :protected protected path(s) skipped. Nothing was written.',
        'files_restored' => 'Every file this run had written was restored from the rollback snapshot.',
        'restore_incomplete' => 'The file restore did not complete. The snapshot is at :path — see docs/upgrades.md §Recovery.',
        'no_files_written' => 'The run failed before any file was written; the installation is untouched.',
        'database_may_be_migrated' => 'Migrations may have been partially applied. Files were restored, but the database was not. Restore the pre-upgrade dump (:backup) before bringing the site back up — see docs/upgrades.md §Recovery.',
        'maintenance_stuck' => 'Maintenance mode is still enabled. Run "php artisan up" once you have finished recovering.',
        'manual_rollback' => 'Files were restored manually from this run’s snapshot.',
        'version_applied' => 'Release :release applied.',
        'version_pinned' => 'Release :release applied, but EMP_CORE_VERSION is pinned in .env so the reported version is still :current. Update .env manually — the upgrade never writes it.',
    ],

    'errors' => [
        'confirmation_mismatch' => 'Type :phrase exactly to confirm the upgrade.',
        'permission' => 'You do not have permission to upgrade this installation.',
        'no_release' => 'There is no newer release to install.',
        'no_download_url' => 'This release has no download URL.',
        'download_failed' => 'The release archive could not be downloaded: :error',
        'download_status' => 'The release archive could not be downloaded (HTTP :status).',
        'download_empty' => 'The release archive downloaded as an empty file.',
        'download_too_large' => 'The release archive exceeds the :max MB download limit.',
        'checksum_mismatch' => 'Checksum verification FAILED. Expected :expected, got :actual. Nothing was changed.',
        'archive_missing' => 'The release archive is missing.',
        'archive_unreadable' => 'The release archive could not be opened.',
        'archive_empty' => 'The release archive contains no files.',
        'archive_unsafe_entry' => 'The release archive contains an unsafe path and was rejected: :entry',
        'archive_path_too_long' => 'The release archive contains an over-long path: :entry',
        'archive_symlink' => 'The release archive contains a symbolic link and was rejected: :entry',
        'archive_too_many_entries' => 'The release archive contains more than :max entries.',
        'archive_too_large' => 'The release archive expands to more than :max MB.',
        'archive_compression_ratio' => 'The release archive contains a suspiciously compressible entry: :entry',
        'archive_blocked_file' => 'The release archive contains a blocked file type (.:extension): :entry',
        'manifest_version_mismatch' => 'The downloaded package declares version :found but the release feed advertised :expected.',
        'entry_unreadable' => 'A file in the release archive could not be read: :entry',
        'snapshot_failed' => 'A rollback snapshot could not be written for :entry — the upgrade was aborted before overwriting it.',
        'write_failed' => 'Could not write :entry.',
        'migrate_failed' => 'Migrations failed: :output',
        'backup_failed' => 'The pre-upgrade backup failed: :error',
        'backup_missing' => 'The pre-upgrade backup produced no database artefact in :path. Check that the "backups:run" cron job is enabled.',
    ],

    'history' => [
        'heading' => 'Upgrade history',
        'empty' => 'No upgrades have been attempted on this installation.',
        'started_at' => 'Started',
        'versions' => 'Versions',
        'status' => 'Result',
        'actor' => 'Started by',
        'system' => 'System',
        'duration' => 'Duration',
        'statuses' => [
            'pending' => 'Pending',
            'running' => 'Interrupted',
            'success' => 'Succeeded',
            'failed' => 'Failed',
            'rolled_back' => 'Rolled back',
            'blocked' => 'Blocked by pre-flight',
        ],
    ],

    'notifications' => [
        'checked' => 'Release feed checked.',
        'blocked' => 'Upgrade blocked by pre-upgrade checks.',
        'dry_run_done' => 'Dry run complete — nothing was changed.',
        'success' => 'Upgrade to :version completed.',
        'failed' => 'Upgrade failed: :error',
        'rolled_back' => 'Upgrade failed and was rolled back.',
        'rollback_done' => 'Files restored from the rollback snapshot.',
        'rollback_unavailable' => 'No rollback snapshot is available for that run.',
    ],

    'mail' => [
        'subject' => 'A new :app release is available: :version',
        'greeting' => 'A new release has been published',
        'line_version' => 'Version :version is available. This installation is running :current.',
        'line_released' => 'Released :date.',
        'line_changelog' => 'Changelog',
        'line_action' => 'Open the upgrade manager to review the pre-upgrade checks and apply it.',
        'line_manual' => 'This release was announced without a verifiable download, so it must be applied manually.',
        'action' => 'Open the upgrade manager',
    ],

    'console' => [
        'no_endpoint' => 'No release feed configured (EMP_UPGRADE_ENDPOINT).',
        'unreachable' => 'The release feed could not be reached.',
        'current' => 'Up to date — running :version.',
        'available' => 'Release :version is available (running :current).',
        'notified' => 'Notified :count recipient(s).',
        'notify_skipped' => 'Already notified about :version.',
        'confirm_required' => 'Refusing to upgrade without --confirm=:phrase.',
        'dry_run_header' => 'DRY RUN — nothing will be written.',
        'blocked' => 'Blocked by pre-upgrade checks:',
        'result' => 'Run :uid finished with status :status.',
    ],
];
