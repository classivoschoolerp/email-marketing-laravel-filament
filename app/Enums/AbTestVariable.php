<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The variables an A/B test may vary (spec §2.3.6 F-2.3.6-003).
 *
 * Each case maps to the campaign_variants column that overrides the campaign's
 * own value.
 *
 * TWO WAYS TO USE SEVERAL VARIABLES AT ONCE:
 *  - the ordinary side-by-side composer (F-2.3.6-001) still means "each
 *    variant carries its own value for each variable", which is what the
 *    original A/B test does and is NOT a factorial design;
 *  - WP-46 added the real thing (F-2.3.6-004): App\Services\Campaigns\AbTest\
 *    MultivariateDesign generates the full cross-product of factors and levels
 *    and records each variant's level map in `campaign_variants.factors`, so
 *    MultivariateAnalysis can report per-FACTOR effects. Designs larger than
 *    AbTestManager::MAX_VARIANTS are refused, never truncated.
 */
enum AbTestVariable: string
{
    case Subject = 'subject';
    case FromName = 'from_name';
    case Content = 'content';
    case SendTime = 'send_time';
    case Preheader = 'preheader';

    public function label(): string
    {
        return __('campaigns.ab.variables.'.$this->value);
    }

    /**
     * Campaign/variant columns this variable owns.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Subject => ['subject'],
            self::FromName => ['from_name'],
            self::Preheader => ['preheader'],
            self::Content => ['template_id', 'content_html', 'content_json'],
            self::SendTime => ['send_delay_minutes'],
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

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
