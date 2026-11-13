<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\FailedJob;
use App\Support\Queue\Queues;
use Illuminate\Support\Facades\DB;

/**
 * Queue depth monitor (spec §3.27 F-3.27-013, WP-17). Horizon cannot be
 * installed here (D-04: ext-pcntl, Windows) — this is the documented honest
 * alternative: pending/reserved counts + oldest-pending age per named queue
 * read straight from the `jobs` table (database driver), plus the failed
 * count. On a non-database queue driver the per-queue depth is reported as
 * unavailable rather than invented.
 */
class QueueMonitorService
{
    public function supported(): bool
    {
        return config('queue.default') === 'database';
    }

    /**
     * @return list<array{queue: string, pending: int, reserved: int, oldest_pending_seconds: ?int}>
     */
    public function queues(): array
    {
        if (! $this->supported()) {
            return [];
        }

        $rows = DB::table('jobs')
            ->selectRaw('queue,'
                .' sum(case when reserved_at is null then 1 else 0 end) as pending,'
                .' sum(case when reserved_at is not null then 1 else 0 end) as reserved,'
                .' min(case when reserved_at is null then created_at else null end) as oldest_pending')
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        $names = collect(Queues::all())
            ->merge($rows->keys())
            ->unique()
            ->values();

        return $names->map(function (string $queue) use ($rows): array {
            $row = $rows->get($queue);

            return [
                'queue' => $queue,
                'pending' => (int) ($row->pending ?? 0),
                'reserved' => (int) ($row->reserved ?? 0),
                // jobs.created_at is a unix timestamp (database queue driver).
                'oldest_pending_seconds' => isset($row->oldest_pending) && $row->oldest_pending !== null
                    ? max(0, time() - (int) $row->oldest_pending)
                    : null,
            ];
        })->all();
    }

    public function totalPending(): int
    {
        return array_sum(array_column($this->queues(), 'pending'));
    }

    public function failedCount(): int
    {
        return (int) FailedJob::query()->count();
    }
}
