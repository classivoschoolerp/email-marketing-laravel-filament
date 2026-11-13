<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WarmupStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-server warmup state (spec §2.9.3 F-2.9.3-004/011). Table name is
 * singular-ish by design (`warmup_progress`) — Eloquent's pluraliser would
 * otherwise produce `warmup_progresses`.
 */
class WarmupProgress extends Model
{
    protected $table = 'warmup_progress';

    protected $guarded = ['id'];

    protected $attributes = [
        'current_day' => 1,
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => WarmupStatus::class,
            'current_day' => 'integer',
            'started_on' => 'date',
            'last_advanced_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WarmupPlan::class, 'warmup_plan_id')->withoutGlobalScopes();
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(DeliveryServer::class, 'delivery_server_id')->withoutGlobalScopes();
    }

    public function dailyUsage(): HasMany
    {
        return $this->hasMany(WarmupDailyUsage::class)->orderByDesc('date');
    }

    /** Does this server's warmup currently impose a ceiling? */
    public function enforces(): bool
    {
        return $this->status->enforces()
            && ($this->plan?->status ?? WarmupStatus::Draft)->enforces();
    }
}
