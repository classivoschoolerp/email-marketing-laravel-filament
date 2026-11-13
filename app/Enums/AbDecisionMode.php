<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the winning variant is chosen (spec §2.3.6 F-2.3.6-007 + F-2.3.6-011).
 *
 *  manual      — nothing is decided automatically; the customer picks.
 *  window      — decide after N hours (F-2.3.6-007), leader on the criterion.
 *  confidence  — decide as soon as the two-proportion z-test between the
 *                leader and the runner-up clears the configured confidence
 *                level (F-2.3.6-011 — "not just a fixed timer"). The window
 *                hours still act as a documented fallback ceiling, because a
 *                small test group may never reach significance and a test that
 *                never ends is a campaign that never sends.
 */
enum AbDecisionMode: string
{
    case Manual = 'manual';
    case Window = 'window';
    case Confidence = 'confidence';

    public function label(): string
    {
        return __('campaigns.ab.decision.'.$this->value);
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
