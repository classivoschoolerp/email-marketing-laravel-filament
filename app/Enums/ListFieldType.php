<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Custom field types (spec F-2.2.9-001). The P1 set plus the two P2
 * additions completed in WP-42: File upload and Geo-city.
 */
enum ListFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Dropdown = 'dropdown';
    case Multiselect = 'multiselect';
    case Checkbox = 'checkbox';
    case CheckboxList = 'checkbox_list';
    case Radio = 'radio';
    case Date = 'date';
    case Datetime = 'datetime';
    case Country = 'country';
    case Zone = 'zone';
    case Phone = 'phone';
    case Number = 'number';
    case Url = 'url';
    case Email = 'email';
    case Consent = 'consent';
    case Rating = 'rating';
    case Hidden = 'hidden';
    // F-2.2.9-001 P2 additions (WP-42).
    case File = 'file';
    case City = 'city';

    public function label(): string
    {
        return __('lists.field_type.'.$this->value);
    }

    /** Choice types that use the option manager (F-2.2.9-003). */
    public function hasOptions(): bool
    {
        return in_array($this, [self::Dropdown, self::Multiselect, self::CheckboxList, self::Radio], true);
    }

    /** Types whose value is a json-encoded array. */
    public function isMultiValue(): bool
    {
        return in_array($this, [self::Multiselect, self::CheckboxList], true);
    }

    public function isBoolean(): bool
    {
        return in_array($this, [self::Checkbox, self::Consent], true);
    }

    public function isNumeric(): bool
    {
        return in_array($this, [self::Number, self::Rating], true);
    }

    public function isDate(): bool
    {
        return in_array($this, [self::Date, self::Datetime], true);
    }

    /**
     * Uploads are stored as a disk-relative path, not as the answer itself
     * (F-2.2.9-001 file upload) — every value codec has to branch on this.
     */
    public function isFile(): bool
    {
        return $this === self::File;
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
