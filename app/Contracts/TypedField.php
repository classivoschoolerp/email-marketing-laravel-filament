<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\ListFieldType;
use App\Models\ListField;
use App\Services\Lists\FieldValueService;
use Illuminate\Support\Collection;

/**
 * The §2.2.9 custom-field contract (WP-31).
 *
 * {@see ListField} defined the 18-type field library; forms
 * (§2.7) and surveys (§2.8) reuse it verbatim rather than growing a second
 * one. This interface is what {@see FieldValueService}
 * accepts, so validation rules, storage serialisation and public rendering
 * are literally the same code for all three field models.
 *
 * Implementations are Eloquent models whose columns already carry these
 * names; the accessors below exist so the contract is checkable rather than
 * relying on magic properties.
 *
 * @property ListFieldType $type
 * @property string $tag
 * @property string $label
 * @property bool $required
 * @property array<string, mixed>|null $validation
 * @property Collection<int, object> $options
 */
interface TypedField
{
    public function fieldType(): ListFieldType;

    public function isRequired(): bool;

    /** @return array<string, mixed> */
    public function validationConfig(): array;

    /** Selectable values for choice types, in display order. @return list<string> */
    public function optionValues(): array;
}
