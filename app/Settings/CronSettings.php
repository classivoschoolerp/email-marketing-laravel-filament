<?php

declare(strict_types=1);

namespace App\Settings;

use App\Console\Commands\AutomationsTickCommand;
use App\Console\Commands\BlacklistMonitorsRunCommand;
use App\Console\Commands\WarmupAdvanceCommand;
use App\Services\Cron\CronRunRecorder;
use App\Services\Settings\Readers\CronSettingsReader;
use Spatie\LaravelSettings\Settings;

/**
 * Cron / scheduler settings (spec §3.25.11).
 *
 * The job list mirrors spec §0.6; WP-13's scheduler consults
 * {@see CronSettingsReader} for batch limits
 * and per-job enablement, and wraps job execution in
 * {@see CronRunRecorder}.
 */
class CronSettings extends Settings
{
    /**
     * Known scheduler jobs (spec §0.6). The heartbeat command
     * (`system:cron-heartbeat`) is monitoring, not a schedulable batch job,
     * so it is deliberately not listed here.
     *
     * @var list<string>
     */
    public const KNOWN_JOBS = [
        'campaigns:send',
        'campaigns:process-autoresponders',
        'campaigns:process-recurring',
        'automations:tick',
        'bounces:process',
        'fbl:process',
        'mailbox-monitors:process',
        'stats:rollup',
        'warmup:advance',
        'sending-domains:verify',
        'tracking-domains:verify',
        'blacklist-monitors:run',
        'subscribers:clean',
        'logs:prune',
        'quotas:reset',
        'license:check',
        'backups:run',
    ];

    /**
     * Canonical jobs whose command ships with an optional module, mapped to
     * that command's class. KNOWN_JOBS stays fixed (D-40); installedJobs()
     * is the part of it this install can actually run.
     *
     * @var array<string, class-string>
     */
    public const MODULE_JOBS = [
        'automations:tick' => AutomationsTickCommand::class,
        'warmup:advance' => WarmupAdvanceCommand::class,
        'blacklist-monitors:run' => BlacklistMonitorsRunCommand::class,
    ];

    /**
     * KNOWN_JOBS minus the module jobs whose module is not installed.
     *
     * @return list<string>
     */
    public static function installedJobs(): array
    {
        return array_values(array_filter(
            self::KNOWN_JOBS,
            static fn (string $job): bool => ! isset(self::MODULE_JOBS[$job]) || class_exists(self::MODULE_JOBS[$job]),
        ));
    }

    // Batch limits (F-3.25.11-001)
    public int $emails_per_batch;

    public int $subscribers_per_batch;

    public int $parallel_processes;

    /**
     * Enabled jobs — subset of KNOWN_JOBS (F-3.25.11-002).
     *
     * @var list<string>
     */
    public array $enabled_jobs;

    // Delivery-and-bounce processing concurrency (F-3.25.11-004)
    public int $delivery_concurrency;

    public int $bounce_concurrency;

    public static function group(): string
    {
        return 'cron';
    }
}
