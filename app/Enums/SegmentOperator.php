<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Segment condition operators (spec F-2.2.10-002/003/004/005). One enum
 * covers every condition family; the per-family subsets below drive both
 * the form options and validation.
 */
enum SegmentOperator: string
{
    // Field operators (F-2.2.10-002)
    case Is = 'is';
    case IsNot = 'is_not';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case GreaterThan = 'greater_than';
    case LessThan = 'less_than';
    case Between = 'between';
    case IsEmpty = 'is_empty';
    case NotEmpty = 'not_empty';

    // Date operators (F-2.2.10-004)
    case Before = 'before';
    case After = 'after';

    // Campaign operators (F-2.2.10-003)
    case Opened = 'opened';
    case NotOpened = 'not_opened';
    case ClickedAny = 'clicked_any';
    case ClickedLink = 'clicked_link';
    case NotClicked = 'not_clicked';

    // Engagement operators (F-2.2.10-006) — value carries the N.
    case OpenedAtLeast = 'opened_at_least';
    case NoOpensInDays = 'no_opens_in_days';

    // Automation operators (F-2.2.10-009) — settings.automation_id carries the target.
    case InAutomation = 'in_automation';
    case CompletedAutomation = 'completed_automation';

    /*
     * Purchase operators (§2.12 F-2.12-006, WP-45). `value` carries the
     * number: MINOR UNITS for the two spend operators (never a float — a
     * segment on "spent over €100" is 10000), a plain count for order
     * frequency, and days for the two recency operators.
     */
    case SpentAtLeast = 'spent_at_least';
    case SpentAtMost = 'spent_at_most';
    case OrderedAtLeast = 'ordered_at_least';
    case OrderedAtMost = 'ordered_at_most';
    case OrderedCategory = 'ordered_category';
    case NotOrderedCategory = 'not_ordered_category';
    case OrderedProduct = 'ordered_product';
    case LastOrderWithinDays = 'last_order_within_days';
    case LastOrderBeforeDays = 'last_order_before_days';
    case NeverOrdered = 'never_ordered';

    public function label(): string
    {
        return __('segments.operator.'.$this->value);
    }

    /** @return list<self> */
    public static function forField(): array
    {
        return [
            self::Is, self::IsNot, self::Contains, self::NotContains,
            self::StartsWith, self::EndsWith, self::GreaterThan, self::LessThan,
            self::Between, self::IsEmpty, self::NotEmpty,
        ];
    }

    /** @return list<self> */
    public static function forDate(): array
    {
        return [self::Before, self::After, self::Between];
    }

    /** @return list<self> */
    public static function forStatus(): array
    {
        return [self::Is, self::IsNot];
    }

    /** @return list<self> */
    public static function forCampaign(): array
    {
        return [self::Opened, self::NotOpened, self::ClickedAny, self::ClickedLink, self::NotClicked];
    }

    /** Engagement counters / recency (F-2.2.10-006). @return list<self> */
    public static function forEngagement(): array
    {
        return [self::OpenedAtLeast, self::NoOpensInDays];
    }

    /** Geo string matching (F-2.2.10-007). @return list<self> */
    public static function forGeo(): array
    {
        return [self::Is, self::IsNot, self::Contains, self::NotContains, self::IsEmpty, self::NotEmpty];
    }

    /** Automation membership (F-2.2.10-009). @return list<self> */
    public static function forAutomation(): array
    {
        return [self::InAutomation, self::CompletedAutomation];
    }

    /** Purchase history (§2.12 F-2.12-006, WP-45). @return list<self> */
    public static function forPurchase(): array
    {
        return [
            self::SpentAtLeast, self::SpentAtMost,
            self::OrderedAtLeast, self::OrderedAtMost,
            self::OrderedCategory, self::NotOrderedCategory, self::OrderedProduct,
            self::LastOrderWithinDays, self::LastOrderBeforeDays,
            self::NeverOrdered,
        ];
    }

    /**
     * @param  list<self>  $cases
     * @return array<string, string> value => label
     */
    public static function optionsFor(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public function needsValue(): bool
    {
        return ! in_array($this, [
            self::IsEmpty, self::NotEmpty, self::ClickedAny, self::NotClicked, self::Opened, self::NotOpened,
            // Automation operators take their target from settings.automation_id.
            self::InAutomation, self::CompletedAutomation,
            // WP-45: "never ordered" is a state, not a comparison.
            self::NeverOrdered,
        ], true);
    }

    public function needsSecondValue(): bool
    {
        return $this === self::Between;
    }
}
