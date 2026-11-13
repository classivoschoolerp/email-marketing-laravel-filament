<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Survey lifecycle (spec F-2.8-002). `Closed` is the terminal state the
 * §1.9 close conditions (end date reached, responder limit hit) put a survey
 * into; `Paused` is a reversible operator action.
 */
enum SurveyStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Closed = 'closed';

    public function label(): string
    {
        return __('surveys.status.'.$this->value);
    }

    /** Only an Active survey can accept new responses (F-1.9-004). */
    public function acceptsResponses(): bool
    {
        return $this === self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Paused => 'warning',
            self::Closed => 'danger',
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
