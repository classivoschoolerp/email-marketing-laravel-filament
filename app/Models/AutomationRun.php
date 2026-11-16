<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationRunStatus;
use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One subscriber journey through an automation (spec §2.4.2/§2.4.3).
 * Tenant-scoped: report drill-downs and index counters read it from the
 * customer panel; the engine always goes through withoutTenancy().
 */
class AutomationRun extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AutomationRunStatus::class,
            'entry_number' => 'integer',
            'version' => 'integer',
            'wake_at' => 'datetime',
            'node_entered_at' => 'datetime',
            'entered_at' => 'datetime',
            'completed_at' => 'datetime',
            'goal_met_at' => 'datetime',
            'conversion_value' => 'integer',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->uid ??= (string) Str::uuid();
        });
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class)->withoutGlobalScopes();
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class)->withoutGlobalScopes();
    }

    public function currentNode(): BelongsTo
    {
        return $this->belongsTo(AutomationNode::class, 'current_node_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AutomationRunLog::class, 'run_id');
    }

    public function isActive(): bool
    {
        return $this->status === AutomationRunStatus::Active;
    }
}
