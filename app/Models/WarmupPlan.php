<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WarmupIncrementType;
use App\Enums\WarmupStatus;
use App\Enums\WarmupStrategy;
use App\Models\Concerns\BelongsToCustomer;
use App\Models\Scopes\CustomerScope;
use App\Support\Tenancy\CurrentCustomer;
use Database\Factories\WarmupPlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Warmup plan (spec §2.9.3, WP-33). customer_id NULL = system plan usable by
 * admin-owned delivery servers; tenant plans are scoped by CustomerScope and
 * visible through {@see scopeBrowsable()} exactly like DeliveryServer.
 *
 * The plan owns the ramp definition; warmup_plan_schedules owns the resolved
 * per-day allowances (editable, F-2.9.3-002) and warmup_progress owns the
 * per-server state (F-2.9.3-011).
 */
class WarmupPlan extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<WarmupPlanFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = [
        'days' => 30,
        'start_volume' => 50,
        'increment_type' => 'fixed',
        'increment_value' => 50,
        'max_daily_volume' => 0,
        'strategy' => 'linear',
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => WarmupStatus::class,
            'strategy' => WarmupStrategy::class,
            'increment_type' => WarmupIncrementType::class,
            'days' => 'integer',
            'start_volume' => 'integer',
            'increment_value' => 'integer',
            'max_daily_volume' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $plan): void {
            $plan->uid ??= (string) Str::uuid();
        });
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(WarmupPlanSchedule::class)->orderBy('day');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(WarmupProgress::class);
    }

    /** Delivery servers currently assigned to this plan (F-2.9.3-003). */
    public function deliveryServers(): HasMany
    {
        return $this->hasMany(DeliveryServer::class, 'warmup_plan_id')->withoutGlobalScopes();
    }

    /** System plan (customer_id NULL)? */
    public function isSystem(): bool
    {
        return $this->customer_id === null;
    }

    /** Own rows + system plans — mirrors DeliveryServer::scopeBrowsable(). */
    public function scopeBrowsable(Builder $query): Builder
    {
        $customer = app(CurrentCustomer::class)->get();

        $query->withoutGlobalScope(CustomerScope::class);

        if ($customer === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($customer): void {
            $q->where($this->qualifyColumn('customer_id'), $customer->id)
                ->orWhereNull($this->qualifyColumn('customer_id'));
        });
    }

    public function isEditableByCustomer(Customer $customer): bool
    {
        return ! $this->isSystem() && (int) $this->customer_id === (int) $customer->id;
    }

    /** Allowance for a plan day, clamped to the schedule's bounds. */
    public function allowanceForDay(int $day): int
    {
        $day = max(1, min($day, max(1, (int) $this->days)));

        $row = $this->schedules()->where('day', $day)->first();

        return $row !== null ? (int) $row->allowance : 0;
    }
}
