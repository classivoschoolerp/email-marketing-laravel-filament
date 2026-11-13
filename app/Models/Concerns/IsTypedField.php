<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Contracts\TypedField;
use App\Enums\ListFieldType;
use App\Services\Lists\FieldValueService;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared body of {@see TypedField} (WP-31): the four
 * accessors {@see FieldValueService} needs, expressed
 * once for list fields (§2.2.9), form fields (§2.7) and survey questions
 * (§2.8).
 *
 * @mixin Model
 */
trait IsTypedField
{
    public function fieldType(): ListFieldType
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return (bool) $this->required;
    }

    /** @return array<string, mixed> */
    public function validationConfig(): array
    {
        return (array) $this->validation;
    }

    /** @return list<string> */
    public function optionValues(): array
    {
        return array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            $this->options->pluck('value')->all(),
        ));
    }
}
