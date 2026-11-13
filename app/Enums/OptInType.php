<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Single/double opt semantics — shared by the list opt-in and opt-out
 * columns (spec F-2.2.2-002/003).
 */
enum OptInType: string
{
    case Single = 'single';
    case Double = 'double';

    public function label(): string
    {
        return __('lists.opt_type.'.$this->value);
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
