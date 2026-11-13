<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Form lifecycle (spec §2.7). Only Active forms render or accept a
 * submission on the public endpoints; Draft/Inactive answer 404 so an
 * embedded snippet on a live page degrades to nothing.
 */
enum FormStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return __('forms.status.'.$this->value);
    }

    public function isPublic(): bool
    {
        return $this === self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'warning',
            self::Draft => 'gray',
        };
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
