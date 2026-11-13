<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\TypedField;
use App\Enums\ListFieldType;
use App\Models\Concerns\BelongsToCustomer;
use App\Models\Concerns\IsTypedField;
use App\Services\Lists\FieldValueService;
use Database\Factories\FormFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One input on a form (spec F-2.7-003) — the §2.2.9 field-type library
 * reused verbatim, so validation and storage go through
 * {@see FieldValueService} exactly like a list field.
 *
 * @property array<string, mixed>|null $validation
 */
class FormField extends Model implements TypedField
{
    use BelongsToCustomer;

    /** @use HasFactory<FormFieldFactory> */
    use HasFactory;

    use IsTypedField;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ListFieldType::class,
            'required' => 'boolean',
            'sort_order' => 'integer',
            // F-2.7-004: 1 for every field of a single-step form.
            'step' => 'integer',
            'validation' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $field): void {
            // Merge tags are canonically uppercase (F-2.2.9-002 convention).
            $field->tag = strtoupper(trim((string) $field->tag));
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** The list custom field the answer is stored on (NULL = metadata only). */
    public function listField(): BelongsTo
    {
        return $this->belongsTo(ListField::class, 'list_field_id');
    }

    public function options(): MorphMany
    {
        return $this->morphMany(FieldOption::class, 'owner')->orderBy('sort_order')->orderBy('id');
    }
}
