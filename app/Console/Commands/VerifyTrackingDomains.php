<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TrackingDomain;
use App\Services\Cron\CronRunRecorder;
use App\Services\Domains\TrackingDomainVerifier;
use App\Services\Settings\Readers\CronSettingsReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * tracking-domains:verify (spec §2.9.8 / §3.13.6 verify-all, WP-15):
 * re-checks the CNAME of every tracking domain across all tenants.
 * CronRunRecorder-wrapped (D-40).
 */
class VerifyTrackingDomains extends Command
{
    public const JOB = 'tracking-domains:verify';

    protected $signature = 'tracking-domains:verify {--domain= : Verify only this tracking domain id}';

    protected $description = 'Re-verify the CNAME records of all tracking domains';

    public function handle(
        CronRunRecorder $recorder,
        CronSettingsReader $cron,
        TrackingDomainVerifier $verifier,
    ): int {
        if (! $cron->jobEnabled(self::JOB)) {
            $this->info(__('infra.cron.disabled', ['job' => self::JOB]));

            return self::SUCCESS;
        }

        $only = $this->option('domain');

        $processed = $recorder->record(self::JOB, function () use ($verifier, $only): int {
            $count = 0;

            TrackingDomain::withoutTenancy()
                ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
                ->chunkById(100, function ($domains) use ($verifier, &$count): void {
                    foreach ($domains as $domain) {
                        try {
                            $verifier->verify($domain);
                            $count++;
                        } catch (Throwable $exception) {
                            Log::error('Tracking domain verification failed.', [
                                'tracking_domain_id' => $domain->id,
                                'error' => $exception->getMessage(),
                            ]);
                        }
                    }
                });

            return $count;
        });

        $this->info(__('infra.cron.processed', ['count' => $processed]));

        return self::SUCCESS;
    }
}
