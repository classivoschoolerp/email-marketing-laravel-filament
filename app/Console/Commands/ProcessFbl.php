<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InfraServerStatus;
use App\Models\FblServer;
use App\Services\Cron\CronRunRecorder;
use App\Services\Fbl\FblProcessor;
use App\Services\Settings\Readers\CronSettingsReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * fbl:process (spec §2.9.5 / §0.6, WP-15): polls every ACTIVE FBL server and
 * processes ARF complaint reports. CronRunRecorder-wrapped (D-40), honours
 * the cron-settings job toggle.
 */
class ProcessFbl extends Command
{
    public const JOB = 'fbl:process';

    protected $signature = 'fbl:process {--server= : Process only this FBL server id}';

    protected $description = 'Poll active feedback-loop servers and process ARF complaints';

    public function handle(
        CronRunRecorder $recorder,
        CronSettingsReader $cron,
        FblProcessor $processor,
    ): int {
        if (! $cron->jobEnabled(self::JOB)) {
            $this->info(__('infra.cron.disabled', ['job' => self::JOB]));

            return self::SUCCESS;
        }

        $only = $this->option('server');

        $processed = $recorder->record(self::JOB, function () use ($processor, $only): int {
            $servers = FblServer::withoutTenancy()
                ->where('status', InfraServerStatus::Active->value)
                ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
                ->get();

            $total = 0;

            foreach ($servers as $server) {
                try {
                    $total += $processor->process($server);
                } catch (Throwable $exception) {
                    Log::error('FBL server processing failed.', [
                        'fbl_server_id' => $server->id,
                        'error' => $exception->getMessage(),
                    ]);
                    $this->error(__('infra.cron.server_failed', [
                        'name' => $server->name,
                        'error' => $exception->getMessage(),
                    ]));
                }
            }

            return $total;
        });

        $this->info(__('infra.cron.processed', ['count' => $processed]));

        return self::SUCCESS;
    }
}
