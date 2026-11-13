<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportSource;
use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One subscriber import run (spec §2.2.7). Counters are updated in place by
 * the queued RunImportJob so the Filament poll widget renders live progress
 * and an ETA (F-2.2.7-011); the finished row doubles as the import history
 * log entry with its summary + error CSV (F-2.2.7-012).
 *
 * @property array<string, mixed>|null $options
 */
class ImportJob extends Model
{
    use BelongsToCustomer;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source' => ImportSource::class,
            'options' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'imported_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'rolled_back_count' => 'integer',
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

    /** Per-import provenance used by the F-2.2.7-013 rollback. */
    public function subscriberLedger(): HasMany
    {
        return $this->hasMany(ImportJobSubscriber::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_COMPLETED_WITH_ERRORS,
            self::STATUS_FAILED,
        ], true);
    }

    /** 0–100, or null before the total is known (F-2.2.7-011). */
    public function progressPercent(): ?int
    {
        if ($this->isFinished()) {
            return 100;
        }

        if ($this->total_rows === null || $this->total_rows === 0) {
            return null;
        }

        return (int) min(100, floor($this->processed_rows / $this->total_rows * 100));
    }

    /** Estimated seconds remaining from the observed row rate (F-2.2.7-011). */
    public function etaSeconds(): ?int
    {
        if ($this->isFinished()
            || $this->started_at === null
            || $this->total_rows === null
            || $this->processed_rows === 0) {
            return null;
        }

        $elapsed = max(1, $this->started_at->diffInSeconds(now()));
        $rate = $this->processed_rows / $elapsed;

        if ($rate <= 0) {
            return null;
        }

        return (int) ceil(max(0, $this->total_rows - $this->processed_rows) / $rate);
    }

    /** Private-disk directory for this tenant's import files (spec §0.2). */
    public function storageDirectory(): string
    {
        $customerUid = (string) $this->customer()->withoutGlobalScopes()->firstOrFail()->uid;

        return 'customers/'.$customerUid.'/imports';
    }
}
