<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Warmup\WarmupQuota;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detailed sent / failed / reserved counters for one warmup day
 * (spec §2.9.3 F-2.9.3-012). Written and read exclusively through
 * {@see WarmupQuota}.
 */
class WarmupDailyUsage extends Model
{
    protected $table = 'warmup_daily_usage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'day' => 'integer',
            'allowance' => 'integer',
            'sent' => 'integer',
            'failed' => 'integer',
            'reserved' => 'integer',
            'settled' => 'integer',
            'baseline_sent' => 'integer',
            'baseline_failed' => 'integer',
            'reserved_at' => 'datetime',
        ];
    }

    public function progress(): BelongsTo
    {
        return $this->belongsTo(WarmupProgress::class, 'warmup_progress_id');
    }

    /** Emails still permitted today (never negative). */
    public function remaining(): int
    {
        return max(0, (int) $this->allowance - (int) $this->sent - (int) $this->reserved);
    }
}
