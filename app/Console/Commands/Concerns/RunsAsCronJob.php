<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\CronRun;
use App\Services\Cron\CronRunRecorder;
use App\Services\Settings\SettingsReader;
use Throwable;

/**
 * D-40 contract for every §0.6 scheduler command: the body only runs when the
 * job is enabled in the §3.25.11 cron settings, and every execution is
 * recorded in cron_runs via CronRunRecorder (success, processed count and
 * failures alike). Commands declare `public const JOB_NAME` (the canonical
 * CronSettings::KNOWN_JOBS name) and implement process().
 */
trait RunsAsCronJob
{
    public function handle(): int
    {
        if (! $this->cronJobEnabled()) {
            $this->info(__('sending.cron.disabled', ['job' => static::JOB_NAME]));

            return self::SUCCESS;
        }

        try {
            $processed = app(CronRunRecorder::class)->record(
                static::JOB_NAME,
                fn (CronRun $run): int => $this->process($run),
            );
        } catch (Throwable $exception) {
            $this->error(__('sending.cron.failed', [
                'job' => static::JOB_NAME,
                'error' => $exception->getMessage(),
            ]));

            return self::FAILURE;
        }

        $this->info(__('sending.cron.finished', [
            'job' => static::JOB_NAME,
            'processed' => $processed,
        ]));

        return self::SUCCESS;
    }

    /** @return int processed count, recorded on the cron_runs row */
    abstract protected function process(CronRun $run): int;

    /** Unknown/unsaved settings (fresh install) fail open — job enabled. */
    private function cronJobEnabled(): bool
    {
        try {
            return SettingsReader::cron()->jobEnabled(static::JOB_NAME);
        } catch (Throwable) {
            return true;
        }
    }
}
