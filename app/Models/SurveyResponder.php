<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SurveyResponderStatus;
use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One person taking a survey (spec F-2.8-005 / F-1.9-002). `uid` is the
 * resume key carried through the multi-step flow — unguessable, so the
 * public wizard needs no session.
 */
class SurveyResponder extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SurveyResponderStatus::class,
            'current_step' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $responder): void {
            $responder->uid ??= (string) Str::uuid();
            $responder->started_at ??= now();
        });
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyResponderAnswer::class);
    }

    /** F-2.8-009: a response with no subscriber attached is anonymous. */
    public function isAnonymous(): bool
    {
        return $this->subscriber_id === null;
    }

    public function isComplete(): bool
    {
        return $this->status === SurveyResponderStatus::Complete;
    }
}
