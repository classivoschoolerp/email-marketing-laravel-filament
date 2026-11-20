<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\TypedField;
use App\Enums\ListFieldType;
use App\Models\Concerns\BelongsToCustomer;
use App\Models\Concerns\IsTypedField;
use Database\Factories\SurveyFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One survey question (spec F-2.8-003) — the §2.2.9 field-type library
 * reused verbatim.
 *
 * @property array<string, mixed>|null $validation
 */
class SurveyField extends Model implements TypedField
{
    use BelongsToCustomer;

    /** @use HasFactory<SurveyFieldFactory> */
    use HasFactory;

    use IsTypedField;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ListFieldType::class,
            'required' => 'boolean',
            'sort_order' => 'integer',
            'validation' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $field): void {
            $field->tag = strtoupper(trim((string) $field->tag));
        });
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /** Write-back target on the subscriber, used by F-2.8-007. */
    public function listField(): BelongsTo
    {
        return $this->belongsTo(ListField::class, 'list_field_id');
    }

    public function options(): MorphMany
    {
        return $this->morphMany(FieldOption::class, 'owner')->orderBy('sort_order')->orderBy('id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SurveyResponderAnswer::class);
    }

    /** Aggregate charts only make sense for a bounded answer domain. */
    public function isAggregatable(): bool
    {
        return $this->type->hasOptions()
            || $this->type->isBoolean()
            || $this->type === ListFieldType::Rating
            || $this->type === ListFieldType::Country;
    }
}
