<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provenance for one subscriber row touched by one import run (spec §2.2.7
 * F-2.2.7-013 rollback). Written in bulk per import batch by RunImportJob
 * and consumed only by RollbackImportJob, which deletes the rows again once
 * the run has been undone.
 *
 * @property array<string, string|null>|null $previous_values
 */
class ImportJobSubscriber extends Model
{
    use BelongsToCustomer;

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'previous_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
