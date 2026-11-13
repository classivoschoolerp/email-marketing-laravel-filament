<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Admin → customer announcement (spec §2.1 F-2.1-010, WP-18). Global model —
 * every customer sees active announcements; dismissals are per customer.
 */
class Announcement extends Model
{
    public const LEVEL_INFO = 'info';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_DANGER = 'danger';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'dismissible' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            // F-4.3-003 broadcast audit (WP-39)
            'broadcast_at' => 'datetime',
            'broadcast_count' => 'integer',
        ];
    }

    /** F-4.3-003 — already pushed to every tenant's notification bell? */
    public function wasBroadcast(): bool
    {
        return $this->broadcast_at !== null;
    }

    public function dismissals(): HasMany
    {
        return $this->hasMany(AnnouncementDismissal::class);
    }

    /** Active now: enabled and inside the optional display window. */
    public function scopeActiveNow(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** Visible to this customer: active now and not dismissed by them. */
    public function scopeVisibleTo(Builder $query, Customer $customer): Builder
    {
        return $query->activeNow()
            ->whereDoesntHave('dismissals', fn (Builder $q) => $q->where('customer_id', $customer->id));
    }

    /** @return array<string, string> value => label */
    public static function levelOptions(): array
    {
        return [
            self::LEVEL_INFO => __('dashboards.announcements.levels.info'),
            self::LEVEL_WARNING => __('dashboards.announcements.levels.warning'),
            self::LEVEL_DANGER => __('dashboards.announcements.levels.danger'),
        ];
    }
}
