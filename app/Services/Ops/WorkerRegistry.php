<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\WorkerNode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * §6.6 F-6.6-008/009 — worker/node registry and graceful drain (WP-24).
 *
 * Rows are written from two places so the picture is complete without any
 * Horizon dependency (D-04):
 *   - the queue event listener (JobProcessing/JobProcessed/JobFailed) records
 *     what each process is doing right now;
 *   - `queue:heartbeat` keeps idle workers visible between jobs.
 *
 * The natural key is (node, pid). "Node" defaults to the machine hostname so
 * dedicated sending nodes (§0.1 distributed deployment) are distinguishable.
 */
class WorkerRegistry
{
    public static function nodeName(): string
    {
        $configured = (string) env('EMP_NODE_NAME', '');

        return $configured !== '' ? $configured : (gethostname() ?: 'unknown');
    }

    public static function pid(): int
    {
        return (int) getmypid();
    }

    /** Register/refresh this process; returns the row. */
    public function heartbeat(
        ?string $queues = null,
        ?string $connection = null,
        ?string $node = null,
        ?int $pid = null,
    ): WorkerNode {
        $node ??= self::nodeName();
        $pid ??= self::pid();

        /** @var WorkerNode $worker */
        $worker = WorkerNode::query()->firstOrNew(['node' => $node, 'pid' => $pid]);

        $worker->fill(array_filter([
            'queues' => $queues,
            'connection' => $connection,
        ], static fn (mixed $value): bool => $value !== null));

        $worker->started_at ??= Carbon::now();
        $worker->last_heartbeat_at = Carbon::now();
        $worker->save();

        return $worker;
    }

    /** A job started on this process. */
    public function jobStarted(string $jobName, ?string $jobId, ?string $queue, ?string $connection, ?string $node = null, ?int $pid = null): WorkerNode
    {
        $worker = $this->heartbeat($queue, $connection, $node, $pid);

        $worker->forceFill([
            'current_job' => mb_substr($jobName, 0, 255),
            'current_job_id' => $jobId === null ? null : mb_substr($jobId, 0, 64),
            'last_heartbeat_at' => Carbon::now(),
        ])->save();

        return $worker;
    }

    /** A job finished (or failed) on this process. */
    public function jobFinished(bool $failed = false, ?string $node = null, ?int $pid = null): ?WorkerNode
    {
        $worker = WorkerNode::query()
            ->where('node', $node ?? self::nodeName())
            ->where('pid', $pid ?? self::pid())
            ->first();

        if ($worker === null) {
            return null;
        }

        $worker->forceFill([
            'current_job' => null,
            'current_job_id' => null,
            'jobs_processed' => $worker->jobs_processed + ($failed ? 0 : 1),
            'jobs_failed' => $worker->jobs_failed + ($failed ? 1 : 0),
            'last_heartbeat_at' => Carbon::now(),
        ])->save();

        return $worker;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, WorkerNode> */
    public function alive()
    {
        return WorkerNode::query()->alive()->orderBy('node')->orderBy('pid')->get();
    }

    /** Delete rows whose last heartbeat is older than the prune window. */
    public function prune(?int $hours = null): int
    {
        $hours ??= (int) config('emp-ops.ops.worker_prune_hours', 24);

        return (int) WorkerNode::query()
            ->where(function ($query) use ($hours): void {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', Carbon::now()->subHours($hours));
            })
            ->delete();
    }

    /* ================================================================ *
     *  F-6.6-009 graceful restart / drain
     * ================================================================ */

    public static function drainKey(): string
    {
        return (string) config('emp-ops.ops.drain_cache_key', 'ops:workers:drain');
    }

    /**
     * Raise the drain flag. Workers poll {@see draining()} between jobs (see
     * the `queue:heartbeat` loop and docs/install-vps.md) and exit cleanly
     * once it is set, so Supervisor restarts them with fresh code.
     */
    public function drain(?string $by = null): void
    {
        Cache::put(self::drainKey(), [
            'at' => Carbon::now()->toIso8601String(),
            'by' => $by,
        ], now()->addMinutes((int) config('emp-ops.ops.drain_ttl_minutes', 60)));
    }

    public function cancelDrain(): void
    {
        Cache::forget(self::drainKey());
    }

    public function draining(): bool
    {
        return Cache::get(self::drainKey()) !== null;
    }

    /** @return array<string, mixed>|null the drain marker, when raised */
    public function drainState(): ?array
    {
        $state = Cache::get(self::drainKey());

        return is_array($state) ? $state : null;
    }
}
