<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MonitorAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Monitor processing log row (spec §2.9.6 F-2.9.6-005). Log table:
 * created_at only.
 */
class EmailBoxMonitorLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action' => MonitorAction::class,
            'matched' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(EmailBoxMonitor::class, 'email_box_monitor_id')->withoutGlobalScopes();
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(EmailBoxMonitorCondition::class, 'email_box_monitor_condition_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class)->withoutGlobalScopes();
    }
}
