<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One frozen membership row of a static segment (spec §2.2.10 F-2.2.10-013).
 *
 * Tenant-owned: the CustomerScope makes another tenant's snapshot invisible
 * even to code that forgets to filter. Written exclusively by the queued
 * RefreshSegmentSnapshotJob (insert/delete only, never updated).
 */
class ListSegmentSnapshotMember extends Model
{
    use BelongsToCustomer;

    public const TABLE = 'list_segment_snapshot_members';

    public const UPDATED_AT = null;

    protected $table = self::TABLE;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(ListSegment::class, 'list_segment_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
