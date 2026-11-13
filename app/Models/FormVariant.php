<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Database\Factories\FormVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One arm of a §2.7 form A/B test (spec F-2.7-013), shaped on
 * {@see LandingPageVariant}.
 *
 * Every override is NULL-means-inherit, which makes "add a control arm" a
 * one-click operation: an arm that overrides nothing is the form exactly as it
 * already is.
 *
 * @property array<string, mixed>|null $styles
 * @property list<int>|null $field_ids
 */
class FormVariant extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<FormVariantFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'styles' => 'array',
            'field_ids' => 'array',
            'is_control' => 'boolean',
            'is_winner' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $variant): void {
            $variant->uid ??= (string) Str::uuid();
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /** The heading this arm shows: its own, else the form's. */
    public function effectiveHeading(Form $form): ?string
    {
        return $this->override($this->heading) ?? $form->heading;
    }

    public function effectiveDescription(Form $form): ?string
    {
        return $this->override($this->description) ?? $form->description;
    }

    public function effectiveSubmitLabel(Form $form): ?string
    {
        return $this->override($this->submit_label) ?? $form->submit_label;
    }

    /**
     * This arm's styling document: its own overrides layered over the form's,
     * so an arm testing one button colour does not have to restate the rest.
     *
     * @return array<string, mixed>
     */
    public function effectiveStyles(Form $form): array
    {
        return array_merge((array) $form->styles, array_filter(
            (array) $this->styles,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        ));
    }

    /**
     * The field set this arm shows, in this arm's order.
     *
     * `field_ids` is customer data, not a trusted join key: ids that are not
     * among the form's own fields are dropped, and a NULL/empty list means
     * "everything the form has, in the form's order" — which is what makes a
     * copy-only arm possible without restating the field list.
     *
     * @param  list<FormField>  $fields
     * @return list<FormField>
     */
    public function effectiveFields(array $fields): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) $this->field_ids,
        )));

        if ($ids === []) {
            return $fields;
        }

        $byId = [];

        foreach ($fields as $field) {
            $byId[(int) $field->id] = $field;
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    private function override(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
