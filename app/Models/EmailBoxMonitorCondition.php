<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MonitorAction;
use App\Enums\MonitorConditionField;
use App\Enums\MonitorConditionOperator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One condition → action rule on a monitor (spec §2.9.6 F-2.9.6-002/003/004).
 */
class EmailBoxMonitorCondition extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'priority' => 10,
        'enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'field' => MonitorConditionField::class,
            'operator' => MonitorConditionOperator::class,
            'action' => MonitorAction::class,
            'priority' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(EmailBoxMonitor::class, 'email_box_monitor_id')->withoutGlobalScopes();
    }

    public function targetList(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'target_list_id')->withoutGlobalScopes();
    }
}
