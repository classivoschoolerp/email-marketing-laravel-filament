<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SurveyResponderStatus;
use App\Enums\SurveyStatus;
use App\Models\Concerns\BelongsToCustomer;
use Database\Factories\SurveyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A survey (spec §2.8) and the subject of the §1.9 public pages.
 *
 * Availability is a pure function of status + window + responder cap
 * ({@see isOpen()}): F-1.9-004 needs no scheduler, because every public
 * request evaluates the same predicate and a survey that has just tripped a
 * condition is closed on the next hit.
 */
class Survey extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<SurveyFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SurveyStatus::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'max_responders' => 'integer',
            'show_progress_bar' => 'boolean',
            'fields_per_step' => 'integer',
            'allow_anonymous' => 'boolean',
            'one_response_per_subscriber' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $survey): void {
            $survey->uid ??= (string) Str::uuid();
        });
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'list_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SurveyField::class)->orderBy('sort_order')->orderBy('id');
    }

    public function responders(): HasMany
    {
        return $this->hasMany(SurveyResponder::class);
    }

    /** Completed responders only — the number F-2.8-001 shows and §1.9 caps. */
    public function completedResponders(): HasMany
    {
        return $this->responders()->where('status', SurveyResponderStatus::Complete->value);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', SurveyStatus::Active->value);
    }

    public function publicName(): string
    {
        return (string) ($this->display_name ?: $this->name);
    }

    /**
     * Ordered questions with their choice options eager-loaded.
     *
     * Deliberately WITHOUT the tenancy scope: the §1.9 public pages resolve a
     * survey by uid and must render identically whoever (if anyone) happens
     * to be logged in. Through the `fields()` relation, a panel session
     * belonging to a different tenant would see zero questions and the public
     * wizard would 404 on a perfectly valid survey.
     *
     * @return list<SurveyField>
     */
    public function orderedFields(): array
    {
        return SurveyField::withoutTenancy()
            ->where('survey_id', $this->id)
            ->with('options')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function completedCount(): int
    {
        return SurveyResponder::withoutTenancy()
            ->where('survey_id', $this->id)
            ->where('status', SurveyResponderStatus::Complete->value)
            ->count();
    }

    /** Has the survey run past its end date? (F-1.9-004) */
    public function isExpired(): bool
    {
        return $this->end_at !== null && $this->end_at->isPast();
    }

    /** Has the survey not started yet? (F-2.8-002 start date) */
    public function isPending(): bool
    {
        return $this->start_at !== null && $this->start_at->isFuture();
    }

    /** Has the responder cap been reached? 0/NULL = unlimited (D-23). */
    public function isFull(): bool
    {
        $max = (int) ($this->max_responders ?? 0);

        return $max > 0 && $this->completedCount() >= $max;
    }

    /**
     * The single availability predicate used by every public route
     * (F-1.9-004). Deliberately not cached: the cap must be exact.
     */
    public function isOpen(): bool
    {
        return $this->status instanceof SurveyStatus
            && $this->status->acceptsResponses()
            && ! $this->isPending()
            && ! $this->isExpired()
            && ! $this->isFull();
    }

    /** Why the survey is closed, for the public closed page. */
    public function closedReason(): string
    {
        return match (true) {
            $this->isPending() => 'not_started',
            $this->isExpired() => 'ended',
            $this->isFull() => 'full',
            default => 'closed',
        };
    }

    /**
     * Questions grouped into wizard steps (F-1.9-001). fields_per_step = 0
     * means one page holding every question.
     *
     * @return list<list<SurveyField>>
     */
    public function steps(): array
    {
        $fields = $this->orderedFields();

        if ($fields === []) {
            return [];
        }

        $perStep = (int) $this->fields_per_step;

        if ($perStep <= 0) {
            return [$fields];
        }

        return array_values(array_map(
            static fn (array $chunk): array => array_values($chunk),
            array_chunk($fields, $perStep),
        ));
    }

    public function stepCount(): int
    {
        return max(1, count($this->steps()));
    }
}
