<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cron\CronRunRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduler heartbeat (spec §0.6 / §3.25.11): scheduled every minute, records
 * a cron_runs row and a cache timestamp so the admin panel can alert when the
 * cron runner stops calling in (monitoring UI arrives with WP-13).
 */
class CronHeartbeat extends Command
{
    public const JOB_NAME = 'system:cron-heartbeat';

    public const CACHE_KEY = 'cron.last_heartbeat_at';

    protected $signature = 'system:cron-heartbeat';

    protected $description = 'Record a scheduler heartbeat (cron run history + cache timestamp).';

    public function handle(CronRunRecorder $recorder): int
    {
        $run = $recorder->start(self::JOB_NAME);

        Cache::put(self::CACHE_KEY, now()->toIso8601String());

        $recorder->finish($run);

        $this->info(__('settings.cron.heartbeat_recorded'));

        return self::SUCCESS;
    }
}
