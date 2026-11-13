<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Automation;
use App\Models\StoreOrder;

/**
 * Segment condition families (spec §2.2.10). P1 set: field / date / status /
 * campaign. P2 adds engagement (F-2.2.10-006), geo (F-2.2.10-007) and
 * automation (F-2.2.10-009) — every family is evaluated by the SAME
 * SegmentQueryBuilder, there is no second evaluation path.
 */
enum SegmentConditionType: string
{
    case Field = 'field';
    case Date = 'date';
    case Status = 'status';
    case Campaign = 'campaign';
    case Engagement = 'engagement';
    case Geo = 'geo';
    case Automation = 'automation';
    /**
     * WP-45 (§2.12 F-2.12-006): purchase-based segmentation — total spend,
     * order frequency, product category and last-order recency, all read from
     * the synced `store_orders` the §2.12 connectors write.
     */
    case Purchase = 'purchase';

    public function label(): string
    {
        return __('segments.condition_type.'.$this->value);
    }

    /**
     * The operator family this condition type accepts. Single source of truth
     * for the form's operator select and the API's validation, so a new
     * condition family never needs a second list kept in sync.
     *
     * @return list<SegmentOperator>
     */
    public function operators(): array
    {
        return match ($this) {
            self::Field => SegmentOperator::forField(),
            self::Date => SegmentOperator::forDate(),
            self::Status => SegmentOperator::forStatus(),
            self::Campaign => SegmentOperator::forCampaign(),
            self::Engagement => SegmentOperator::forEngagement(),
            self::Geo => SegmentOperator::forGeo(),
            self::Automation => SegmentOperator::forAutomation(),
            self::Purchase => SegmentOperator::forPurchase(),
        };
    }

    /**
     * Automation and purchase conditions read data owned by optional modules
     * (automations, e-commerce); they are offered only when that module is
     * installed.
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::Automation => class_exists(Automation::class),
            self::Purchase => class_exists(StoreOrder::class),
            default => true,
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case->isAvailable()) {
                $options[$case->value] = $case->label();
            }
        }

        return $options;
    }
}
