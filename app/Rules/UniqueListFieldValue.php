<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\ListField;
use App\Services\Lists\FieldValueService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Unique-value enforcement for a list custom field (spec F-2.2.9-005). A rule
 * object rather than a closure so it can be attached from a Filament
 * component (whose rule closures are evaluated by the component evaluator,
 * not by the validator) and from a plain FormRequest/Validator alike.
 *
 * The comparison runs on the stored representation via
 * {@see FieldValueService::valueTaken()}, so a multiselect compares its
 * canonical json and a date its canonical format — never the raw request
 * string.
 */
final class UniqueListFieldValue implements ValidationRule
{
    public function __construct(
        private readonly ListField $field,
        private readonly ?int $ignoreSubscriberId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! (bool) $this->field->is_unique) {
            return;
        }

        if (app(FieldValueService::class)->valueTaken($this->field, $value, $this->ignoreSubscriberId)) {
            $fail(__('lists.fields.not_unique', ['label' => $this->field->label]));
        }
    }
}
