<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\WorkerRegistry;
use Illuminate\Console\Command;

/**
 * `queue:heartbeat` (spec §6.6 F-6.6-008/009, WP-24).
 *
 * Runs alongside each `queue:work` process (same Supervisor program group)
 * and keeps its node visible in the worker registry between jobs — queue
 * events alone would make an idle node look dead.
 *
 * It also observes the graceful-drain flag (F-6.6-009): once an operator
 * raises it from the admin Workers page, the heartbeat exits with code 0 so
 * Supervisor restarts the group with fresh code, and the accompanying
 * `queue:work --max-time` process finishes its current job and stops.
 */
class QueueHeartbeatCommand extends Command
{
    protected $signature = 'queue:heartbeat
        {--queues= : comma-separated queue names this node serves}
        {--connection= : queue connection name}
        {--interval=30 : seconds between beats}
        {--once : write a single beat and exit (used by tests/cron)}';

    protected $description = 'Keep this worker node visible in the §6.6 worker registry.';

    public function handle(WorkerRegistry $registry): int
    {
        $queues = $this->option('queues');
        $connection = $this->option('connection');
        $interval = max(5, (int) $this->option('interval'));

        do {
            if ($registry->draining()) {
                $this->info(__('ops.workers.draining_exit'));

                return self::SUCCESS;
            }

            $worker = $registry->heartbeat(
                queues: is_string($queues) && $queues !== '' ? $queues : null,
                connection: is_string($connection) && $connection !== '' ? $connection : null,
            );

            $this->line(__('ops.workers.beat', [
                'node' => $worker->node,
                'pid' => $worker->pid,
            ]));

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            sleep($interval);
        } while (true);
    }
}
