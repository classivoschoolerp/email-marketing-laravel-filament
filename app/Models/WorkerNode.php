<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Settings\SettingsReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * §6.6 F-6.6-008 worker/node registry row: one per OS worker process.
 */
class WorkerNode extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'jobs_processed' => 'integer',
            'jobs_failed' => 'integer',
            'pid' => 'integer',
        ];
    }

    public static function staleSeconds(): int
    {
        try {
            return SettingsReader::ops()->workerStaleSeconds();
        } catch (Throwable) {
            return (int) config('emp-ops.ops.worker_stale_seconds', 120);
        }
    }

    public function scopeAlive(Builder $query): Builder
    {
        return $query->where('last_heartbeat_at', '>=', Carbon::now()->subSeconds(self::staleSeconds()));
    }

    public function scopeStale(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('last_heartbeat_at')
                ->orWhere('last_heartbeat_at', '<', Carbon::now()->subSeconds(self::staleSeconds()));
        });
    }

    public function alive(): bool
    {
        return $this->last_heartbeat_at !== null
            && $this->last_heartbeat_at->greaterThanOrEqualTo(Carbon::now()->subSeconds(self::staleSeconds()));
    }

    /** @return list<string> */
    public function queueList(): array
    {
        $queues = (string) ($this->queues ?? '');

        return $queues === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $queues))));
    }
}
