<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\CronSettings;

/**
 * Typed accessors for the `cron` settings group (spec §3.25.11).
 * Consumed by the scheduler work package (WP-13) around real jobs.
 */
final readonly class CronSettingsReader
{
    public function __construct(private CronSettings $settings) {}

    public function emailsPerBatch(): int
    {
        return $this->settings->emails_per_batch;
    }

    public function subscribersPerBatch(): int
    {
        return $this->settings->subscribers_per_batch;
    }

    public function parallelProcesses(): int
    {
        return $this->settings->parallel_processes;
    }

    /**
     * @return list<string>
     */
    public function enabledJobs(): array
    {
        return $this->settings->enabled_jobs;
    }

    /**
     * Unknown job names are treated as disabled.
     */
    public function jobEnabled(string $job): bool
    {
        return in_array($job, $this->settings->enabled_jobs, true);
    }

    public function deliveryConcurrency(): int
    {
        return $this->settings->delivery_concurrency;
    }

    public function bounceConcurrency(): int
    {
        return $this->settings->bounce_concurrency;
    }
}
