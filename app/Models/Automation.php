<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationStatus;
use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Automation / journey (spec §2.4). Draft graph lives in `canvas`; the
 * published executable graph is the `nodes()` relation (materialized by
 * GraphPublisher). Counters are engine-maintained (Enroller/RunAdvancer).
 */
class Automation extends Model
{
    use BelongsToCustomer;
    use HasFactory;
    use SoftDeletes;

    public const REENTRY_ONCE = 'once';

    public const REENTRY_MULTIPLE = 'multiple';

    public const REENTRY_COOLDOWN = 'cooldown';

    public const REENTRY_MODES = [self::REENTRY_ONCE, self::REENTRY_MULTIPLE, self::REENTRY_COOLDOWN];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AutomationStatus::class,
            'trigger_config' => 'array',
            'canvas' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
            'segment_seeded_at' => 'datetime',
            'reentry_cooldown_minutes' => 'integer',
            'entered_count' => 'integer',
            'active_count' => 'integer',
            'completed_count' => 'integer',
            'converted_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $automation): void {
            $automation->uid ??= (string) Str::uuid();
        });
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(AutomationNode::class)->orderBy('sort');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AutomationVersion::class)->orderByDesc('version');
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'email_list_id');
    }

    public function triggerType(): ?AutomationNodeType
    {
        return $this->trigger_type !== null ? AutomationNodeType::tryFrom($this->trigger_type) : null;
    }

    public function isActive(): bool
    {
        return $this->status === AutomationStatus::Active;
    }

    public function isPublished(): bool
    {
        return (int) $this->version > 0;
    }

    public function conversionRate(): ?float
    {
        $entered = (int) $this->entered_count;

        return $entered > 0 ? round((int) $this->converted_count / $entered * 100, 1) : null;
    }
}
