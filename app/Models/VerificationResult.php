<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One verified address inside a verification run (§2.9.12) — the source of
 * the downloadable result CSV (F-2.9.12-008) and the history drill-down
 * (F-2.9.12-009). Not to be confused with the in-memory DTO
 * App\Services\Verification\VerificationResult (a provider's answer).
 */
class VerificationResult extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(VerificationJob::class, 'verification_job_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
