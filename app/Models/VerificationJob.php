<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One §2.9.12 verification run (WP-16). Counters are bumped in place by the
 * queued VerifyBatchJob so the Filament table renders a live progress bar +
 * credit consumption (F-2.9.12-008); finished rows double as the per-list
 * verification history (F-2.9.12-009) and feed the "verified within N days"
 * campaign preflight check (F-2.9.12-010).
 *
 * @property array<int, int>|null $subscriber_ids
 */
class VerificationJob extends Model
{
    use BelongsToCustomer;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const TARGET_LIST = 'list';

    public const TARGET_SEGMENT = 'segment';

    public const TARGET_SUBSCRIBERS = 'subscribers';

    public const TARGET_SINGLE = 'single';

    /** Auto-action applied to undeliverable results (F-2.9.12-007). */
    public const ACTION_TAG = 'tag';

    public const ACTION_DELETE = 'delete';

    public const ACTION_UNSUBSCRIBE = 'unsubscribe';

    public const ACTION_BLACKLIST = 'blacklist';

    /** @return list<string> */
    public const ACTIONS = [
        self::ACTION_TAG,
        self::ACTION_DELETE,
        self::ACTION_UNSUBSCRIBE,
        self::ACTION_BLACKLIST,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subscriber_ids' => 'array',
            'total' => 'integer',
            'processed' => 'integer',
            'deliverable_count' => 'integer',
            'undeliverable_count' => 'integer',
            'risky_count' => 'integer',
            'unknown_count' => 'integer',
            'credits_used' => 'integer',
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
        return $this->belongsTo(ListSegment::class, 'segment_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(VerificationResult::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /** 0–100, or null before the total is known (F-2.9.12-008). */
    public function progressPercent(): ?int
    {
        if ($this->isFinished()) {
            return 100;
        }

        if ($this->total === 0) {
            return null;
        }

        return (int) min(100, floor($this->processed / $this->total * 100));
    }

    /** Private-disk directory for this tenant's verification files (§0.2). */
    public function storageDirectory(): string
    {
        $customerUid = (string) $this->customer()->withoutGlobalScopes()->firstOrFail()->uid;

        return 'customers/'.$customerUid.'/verifications';
    }
}
