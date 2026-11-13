<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a subscriber entered the list (spec §2.2.3 source breakdown).
 */
enum SubscriberSource: string
{
    case Form = 'form';
    case Import = 'import';
    case Api = 'api';
    case Manual = 'manual';
    case Automation = 'automation';

    public function label(): string
    {
        return __('subscribers.source.'.$this->value);
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
