<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Cron\CronRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One scheduler job execution (spec §3.25.11, F-3.25.11-003).
 * Written exclusively through {@see CronRunRecorder}.
 *
 * @property int $id
 * @property string $job
 * @property string $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_ms
 * @property int|null $processed
 * @property string|null $error
 */
class CronRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'job',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'processed',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'processed' => 'integer',
        ];
    }
}
