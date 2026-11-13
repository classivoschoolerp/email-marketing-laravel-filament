<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * §2.13 F-2.13-005 "Notes & call logs". A call log IS a note with three extra
 * columns (direction, outcome, duration) — one table, one timeline entry
 * shape, two labels.
 */
enum CrmNoteType: string
{
    case Note = 'note';
    case Call = 'call';

    public function label(): string
    {
        return __('crm.note_type.'.$this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
