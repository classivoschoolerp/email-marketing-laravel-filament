<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;

/**
 * Canonical frequency document for a form (spec F-2.7-006).
 *
 * Enforced in the browser against localStorage, because the rule is about a
 * VISITOR (who has no account and no cookie we control on a third-party
 * site), not about a subscriber. The server ships the numbers; the loader
 * keeps the clock.
 *
 * Keys
 *  - hide_days_after_close   int  don't show again for N days after dismissal (0 = show again next page)
 *  - hide_after_submit       bool never show again once this visitor submitted
 *  - hide_after_submit_days  int  … for N days (0 = forever, D-23)
 *  - max_shows_per_visitor   int  hard cap on impressions per visitor (0 = unlimited)
 */
final class FrequencyRules
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'hide_days_after_close' => 7,
            'hide_after_submit' => true,
            'hide_after_submit_days' => 0,
            'max_shows_per_visitor' => 0,
        ];
    }

    /** @return array<string, mixed> */
    public static function for(Form $form): array
    {
        return self::normalize((array) $form->frequency_rules);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $defaults = self::defaults();

        return [
            'hide_days_after_close' => max(0, (int) ($raw['hide_days_after_close'] ?? $defaults['hide_days_after_close'])),
            'hide_after_submit' => (bool) ($raw['hide_after_submit'] ?? $defaults['hide_after_submit']),
            'hide_after_submit_days' => max(0, (int) ($raw['hide_after_submit_days'] ?? 0)),
            'max_shows_per_visitor' => max(0, (int) ($raw['max_shows_per_visitor'] ?? 0)),
        ];
    }
}
