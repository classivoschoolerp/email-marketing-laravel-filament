<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of a warmup plan's generated schedule (spec §2.9.3 F-2.9.3-002).
 * `edited` survives regeneration so hand-tuned days are never silently
 * overwritten (WarmupScheduleBuilder::regenerate()).
 */
class WarmupPlanSchedule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'allowance' => 'integer',
            'edited' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WarmupPlan::class, 'warmup_plan_id')->withoutGlobalScopes();
    }
}
