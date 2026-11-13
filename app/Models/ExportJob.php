<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportFormat;
use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One subscriber export run (spec §2.2.8); the row is also the export
 * history entry (F-2.2.8-005). The CSV lives on the local (private) disk at
 * customers/{uid}/exports/{uuid}.csv and is only reachable through the
 * signed, policy-checked download route; expires_at drives both the signed
 * URL lifetime and the exports:prune cleanup.
 *
 * @property array<int, string> $columns
 * @property array<string, mixed>|null $filters
 * @property array<int, int>|null $subscriber_ids
 */
class ExportJob extends Model
{
    use BelongsToCustomer;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const SCOPE_ALL = 'all';

    public const SCOPE_FILTERED = 'filtered';

    public const SCOPE_SEGMENT = 'segment';

    public const SCOPE_SELECTED = 'selected';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'filters' => 'array',
            // F-2.3.8-018 CSV/XLSX chooser (WP-26)
            'format' => ExportFormat::class,
            'subscriber_ids' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $job): void {
            $job->uid ??= (string) Str::uuid();
        });
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'list_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(ListSegment::class, 'list_segment_id');
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->file_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** Private-disk directory for this tenant's export files (spec §0.2). */
    public function storageDirectory(): string
    {
        $customerUid = (string) $this->customer()->withoutGlobalScopes()->firstOrFail()->uid;

        return 'customers/'.$customerUid.'/exports';
    }
}
