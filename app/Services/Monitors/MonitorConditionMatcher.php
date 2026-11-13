<?php

declare(strict_types=1);

namespace App\Services\Monitors;

use App\Enums\MonitorConditionField;
use App\Enums\MonitorConditionOperator;
use App\Models\EmailBoxMonitorCondition;

/**
 * Evaluates one monitor condition against a parsed message (spec §2.9.6
 * F-2.9.6-002). "contains" is case-insensitive substring matching; "regex"
 * is a full PCRE pattern with delimiters. A pattern that fails to compile
 * never matches — a broken rule must not silently unsubscribe everybody.
 */
class MonitorConditionMatcher
{
    public function matches(EmailBoxMonitorCondition $condition, MonitorMessage $message): bool
    {
        $haystack = $this->haystack($condition->field, $message);
        $needle = (string) $condition->value;

        if ($needle === '') {
            return false; // an empty condition matches nothing, by design
        }

        return match ($condition->operator) {
            MonitorConditionOperator::Contains => mb_stripos($haystack, $needle) !== false,
            MonitorConditionOperator::NotContains => mb_stripos($haystack, $needle) === false,
            MonitorConditionOperator::Regex => @preg_match($needle, $haystack) === 1,
        };
    }

    private function haystack(MonitorConditionField $field, MonitorMessage $message): string
    {
        return match ($field) {
            MonitorConditionField::Subject => $message->subject,
            MonitorConditionField::Body => $message->body,
            MonitorConditionField::From => $message->from,
        };
    }
}
