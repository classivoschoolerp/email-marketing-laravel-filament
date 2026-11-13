<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Segment condition combination (spec F-2.2.10-001): ALL = AND, ANY = OR.
 */
enum SegmentMatchType: string
{
    case All = 'all';
    case Any = 'any';

    public function label(): string
    {
        return __('segments.match.'.$this->value);
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
