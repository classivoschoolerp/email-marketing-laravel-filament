<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\CronRun;
use Closure;
use Throwable;

/**
 * Records scheduler job executions into cron_runs (spec §3.25.11,
 * F-3.25.11-003). WP-13 wraps the real §0.6 jobs with this recorder — either
 * via start()/finish()/fail() or the record() convenience wrapper.
 */
class CronRunRecorder
{
    public function start(string $job): CronRun
    {
        return CronRun::query()->create([
            'job' => $job,
            'status' => CronRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function finish(CronRun $run, ?int $processed = null): CronRun
    {
        return $this->complete($run, CronRun::STATUS_SUCCESS, $processed);
    }

    public function fail(CronRun $run, Throwable|string $error, ?int $processed = null): CronRun
    {
        $message = $error instanceof Throwable
            ? $error::class.': '.$error->getMessage()
            : $error;

        return $this->complete($run, CronRun::STATUS_FAILED, $processed, $message);
    }

    /**
     * Wraps a job body: starts a run, finishes it with the processed count
     * (when the callback returns an int) and marks it failed — rethrowing —
     * on any exception.
     *
     * @template TReturn
     *
     * @param  Closure(CronRun): TReturn  $callback
     * @return TReturn
     */
    public function record(string $job, Closure $callback): mixed
    {
        $run = $this->start($job);

        try {
            $result = $callback($run);
        } catch (Throwable $exception) {
            $this->fail($run, $exception);

            throw $exception;
        }

        $this->finish($run, is_int($result) ? $result : null);

        return $result;
    }

    private function complete(CronRun $run, string $status, ?int $processed, ?string $error = null): CronRun
    {
        $finishedAt = now();

        $run->forceFill([
            'status' => $status,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, (int) $run->started_at->diffInMilliseconds($finishedAt)),
            'processed' => $processed,
            'error' => $error,
        ])->save();

        return $run;
    }
}
