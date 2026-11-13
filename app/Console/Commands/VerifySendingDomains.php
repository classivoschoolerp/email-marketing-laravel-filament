<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SendingDomain;
use App\Services\Cron\CronRunRecorder;
use App\Services\Domains\SendingDomainVerifier;
use App\Services\Settings\Readers\CronSettingsReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * sending-domains:verify (spec §2.9.7 F-2.9.7-004 daily auto
 * re-verification / §3.13.5 verify-all, WP-15): re-checks the DNS records of
 * EVERY sending domain across all tenants. CronRunRecorder-wrapped (D-40).
 */
class VerifySendingDomains extends Command
{
    public const JOB = 'sending-domains:verify';

    protected $signature = 'sending-domains:verify {--domain= : Verify only this sending domain id}';

    protected $description = 'Re-verify DNS records of all sending domains';

    public function handle(
        CronRunRecorder $recorder,
        CronSettingsReader $cron,
        SendingDomainVerifier $verifier,
    ): int {
        if (! $cron->jobEnabled(self::JOB)) {
            $this->info(__('infra.cron.disabled', ['job' => self::JOB]));

            return self::SUCCESS;
        }

        $only = $this->option('domain');

        $processed = $recorder->record(self::JOB, function () use ($verifier, $only): int {
            $count = 0;

            SendingDomain::withoutTenancy()
                ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
                ->chunkById(100, function ($domains) use ($verifier, &$count): void {
                    foreach ($domains as $domain) {
                        try {
                            $verifier->verify($domain);
                            $count++;
                        } catch (Throwable $exception) {
                            Log::error('Sending domain verification failed.', [
                                'sending_domain_id' => $domain->id,
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
