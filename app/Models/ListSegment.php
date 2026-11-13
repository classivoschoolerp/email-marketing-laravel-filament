<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SegmentMatchType;
use App\Models\Concerns\BelongsToCustomer;
use Database\Factories\ListSegmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A segment (spec §2.2.10). Stores conditions only — membership is evaluated
 * at read time via SegmentQueryBuilder (F-2.2.10-012), UNLESS the segment is
 * marked static (F-2.2.10-013), in which case membership comes from the
 * frozen snapshot rows (ListSegmentSnapshotMember).
 */
class ListSegment extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<ListSegmentFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'match_type' => SegmentMatchType::class,
            'is_static' => 'boolean',
            'snapshot_taken_at' => 'datetime',
            'snapshot_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $segment): void {
            $segment->uid ??= (string) Str::uuid();
        });

        // A copy (duplicate action, list copy) never inherits the original's
        // snapshot: the rows are not copied, so claiming a snapshot would make
        // the copy silently resolve to nobody. It starts unfrozen instead.
        static::replicating(function (self $segment): void {
            $segment->snapshot_taken_at = null;
            $segment->snapshot_count = 0;
        });
    }

    /** Frozen membership rows (F-2.2.10-013). */
    public function snapshotMembers(): HasMany
    {
        return $this->hasMany(ListSegmentSnapshotMember::class, 'list_segment_id');
    }

    /** True once a static segment actually has a snapshot to resolve from. */
    public function hasSnapshot(): bool
    {
        return $this->snapshot_taken_at !== null;
    }

    /** A static segment resolves from its snapshot instead of its conditions. */
    public function isStatic(): bool
    {
        return (bool) $this->is_static;
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'list_id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(ListSegmentCondition::class)->orderBy('sort_order');
    }

    /**
     * Segment picker API for later work packages ("use segment as campaign
     * target", F-2.2.10-014): id => name options for a list.
     *
     * @return array<int, string>
     */
    public static function pickerOptions(EmailList $list): array
    {
        return self::query()
            ->where('list_id', $list->id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
