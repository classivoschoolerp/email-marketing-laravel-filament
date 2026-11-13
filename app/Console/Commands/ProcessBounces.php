<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InfraServerStatus;
use App\Models\BounceServer;
use App\Services\Bounces\BounceProcessor;
use App\Services\Cron\CronRunRecorder;
use App\Services\Settings\Readers\CronSettingsReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * bounces:process (spec §2.9.4 / §0.6, WP-15): polls every ACTIVE bounce
 * server, parses + classifies + acts on bounce messages. Body wrapped in
 * CronRunRecorder (D-40); skipped cleanly when the job is disabled in the
 * cron settings. One failing server never blocks the rest.
 */
class ProcessBounces extends Command
{
    public const JOB = 'bounces:process';

    protected $signature = 'bounces:process {--server= : Process only this bounce server id}';

    protected $description = 'Poll active bounce servers and process bounce messages';

    public function handle(
        CronRunRecorder $recorder,
        CronSettingsReader $cron,
        BounceProcessor $processor,
    ): int {
        if (! $cron->jobEnabled(self::JOB)) {
            $this->info(__('infra.cron.disabled', ['job' => self::JOB]));

            return self::SUCCESS;
        }

        $only = $this->option('server');

        $processed = $recorder->record(self::JOB, function () use ($processor, $only): int {
            $servers = BounceServer::withoutTenancy()
                ->where('status', InfraServerStatus::Active->value)
                ->when($only !== null, fn ($q) => $q->whereKey((int) $only))
                ->get();

            $total = 0;

            foreach ($servers as $server) {
                try {
                    $total += $processor->process($server);
                } catch (Throwable $exception) {
                    Log::error('Bounce server processing failed.', [
                        'bounce_server_id' => $server->id,
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
