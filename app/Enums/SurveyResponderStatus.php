<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a responder is in the multi-step public flow (spec §1.9). Only
 * `Complete` rows count toward max_responders, appear in the aggregate stats
 * and raise `survey_completed` on the automation bus (F-2.4.2-012).
 */
enum SurveyResponderStatus: string
{
    case Incomplete = 'incomplete';
    case Complete = 'complete';

    public function label(): string
    {
        return __('surveys.responder_status.'.$this->value);
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
