<?php

declare(strict_types=1);

namespace App\Filament\Customer\Support;

use App\Enums\ListFieldType;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Rules\UniqueListFieldValue;
use App\Services\Lists\FieldValueService;
use App\Support\Geo\Countries;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders a ListField as the correct Filament component for its type
 * (spec F-2.2.6-001) with the field's validation rules attached
 * (F-2.2.9-004).
 */
final class ListFieldInputs
{
    public static function make(ListField $field, string $statePrefix = 'field_values'): Component
    {
        $name = $statePrefix.'.'.$field->id;

        $component = self::base($field, $name);

        $component
            ->label($field->label)
            ->helperText((string) ($field->help_text ?? ''))
            ->required($field->required)
            ->rules(self::rules($field))
            ->default(self::defaultFor($field));

        // Unique-value enforcement (F-2.2.9-005) on the panel's create/edit
        // form. The rule object is built per render so the subscriber being
        // edited is excluded from its own uniqueness check; on create there
        // is no record yet and nothing is excluded. Uploads are excluded:
        // their state is a pending temporary file, not the stored value.
        if ($field->is_unique && ! $field->type->isFile()) {
            $component->rule(
                fn (?Model $record): UniqueListFieldValue => new UniqueListFieldValue(
                    $field,
                    $record instanceof Subscriber ? (int) $record->id : null,
                ),
            );
        }

        return $component;
    }

    private static function base(ListField $field, string $name): Field
    {
        $options = fn (): array => $field->options->pluck('label', 'value')->all();

        return match ($field->type) {
            ListFieldType::Textarea => Textarea::make($name)->rows(3),
            ListFieldType::Dropdown => Select::make($name)->options($options),
            ListFieldType::Multiselect => Select::make($name)->options($options)->multiple(),
            ListFieldType::Checkbox => Checkbox::make($name),
            ListFieldType::CheckboxList => CheckboxList::make($name)->options($options),
            ListFieldType::Radio => Radio::make($name)->options($options),
            ListFieldType::Date => DatePicker::make($name),
            ListFieldType::Datetime => DateTimePicker::make($name)->seconds(false),
            ListFieldType::Country => Select::make($name)->options(Countries::all())->searchable(),
            ListFieldType::Zone => TextInput::make($name)->maxLength(100),
            ListFieldType::Phone => TextInput::make($name)->tel(),
            ListFieldType::Number => TextInput::make($name)->numeric(),
            ListFieldType::Url => TextInput::make($name)->url(),
            ListFieldType::Email => TextInput::make($name)->email(),
            ListFieldType::Consent => Checkbox::make($name),
            ListFieldType::Rating => Select::make($name)->options(self::ratingOptions($field)),
            // F-2.2.9-001 P2 types: the upload lands on the tenant's asset
            // path (D-26); geo-city mirrors the Zone field's plain input.
            ListFieldType::File => FileUpload::make($name)
                ->disk('public')
                ->directory(self::uploadDirectory($field)),
            ListFieldType::City => TextInput::make($name)->maxLength(100),
            // Hidden fields stay editable in the panel; they are hidden on
            // public forms only (rendered by the forms work package).
            ListFieldType::Hidden, ListFieldType::Text => TextInput::make($name),
        };
    }

    /** Tenant asset path for uploads (D-26): `customers/{uid}/list-fields/{listUid}`. */
    private static function uploadDirectory(ListField $field): string
    {
        $list = $field->list;
        $customer = $list?->customer()->withoutGlobalScopes()->first();

        return $customer === null || $list === null
            ? 'customers/unknown/list-fields'
            : 'customers/'.$customer->uid.'/list-fields/'.$list->uid;
    }

    /** @return list<mixed> */
    private static function rules(ListField $field): array
    {
        return array_values(array_filter(
            app(FieldValueService::class)->rulesFor($field),
            fn (mixed $rule): bool => ! in_array($rule, ['required', 'nullable'], true),
        ));
    }

    private static function defaultFor(ListField $field): mixed
    {
        $default = $field->default_value;

        if ($default === null || $default === '') {
            return $field->type->isMultiValue() ? [] : null;
        }

        return app(FieldValueService::class)->formValue($field, (string) $default);
    }

    /** @return array<int, int> */
    private static function ratingOptions(ListField $field): array
    {
        $config = (array) $field->validation;
        $min = (int) ($config['min_value'] ?? 1);
        $max = (int) ($config['max_value'] ?? 5);

        $options = [];

        for ($i = $min; $i <= $max; $i++) {
            $options[$i] = $i;
        }

        return $options;
    }
}
