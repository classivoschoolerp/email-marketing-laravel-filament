<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\ConversionEvent;

/**
 * Winner criteria (spec §2.3.6 F-2.3.6-006).
 *
 * HISTORY, kept because it explains the shape of this enum: WP-35 shipped
 * `Conversions` as a DECLARED-BUT-UNAVAILABLE case. There was no conversion
 * producer in the product — goal tracking / the conversion pixel was
 * F-2.3.4-024 (P3) and revenue attribution was F-2.3.8-002 (P3) — so
 * {@see isAvailable()} reported false, the case was hidden from the UI, and
 * AbWinnerSelector refused to decide on it rather than inventing a number.
 *
 * WP-45 landed both P3 rows. `conversion_events` is now a real table with two
 * real producers (the goal pixel and RevenueAttributor), CampaignReportService
 * reports the figure, and AbTestStats splits it per variant through
 * `campaign_variant_recipients`. So the case is available — but ONLY when the
 * campaign actually has a conversion source, which is what
 * {@see isAvailableFor()} answers: a campaign with goal tracking switched off
 * and no connected store still has no data, and the selector must still refuse
 * rather than declare a 0-vs-0 winner.
 *
 * That distinction is the honest one: "the product can measure conversions"
 * (isAvailable) and "this campaign can" (isAvailableFor) are different
 * questions, and the second is the one a winner decision hangs on.
 */
enum AbWinnerCriteria: string
{
    case UniqueOpens = 'unique_opens';
    case UniqueClicks = 'unique_clicks';
    case ClickToOpen = 'click_to_open';
    case Conversions = 'conversions';

    public function label(): string
    {
        return __('campaigns.ab.criteria.'.$this->value);
    }

    /**
     * True when a real data source exists for this criterion in the product.
     *
     * All four are available since WP-45 (§2.12). Whether a PARTICULAR
     * campaign can produce the number is {@see isAvailableFor()}.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Can THIS campaign produce the criterion?
     *
     * Only `Conversions` can answer no, and it does so when the campaign has
     * recorded no conversion at all AND has not opted into goal tracking —
     * i.e. nothing could ever arrive. A campaign with goal tracking on but no
     * conversions yet stays "available": that is a legitimately empty
     * measurement the window/confidence logic already handles (a 0-vs-0
     * proportion is never significant, and the window mode ranks by the
     * documented total ordering).
     */
    public function isAvailableFor(object $campaign): bool
    {
        if ($this !== self::Conversions) {
            return true;
        }

        if ((bool) ($campaign->goal_tracking_enabled ?? false)) {
            return true;
        }

        return ConversionEvent::query()
            ->where('customer_id', $campaign->customer_id)
            ->where('campaign_uid', $campaign->uid)
            ->exists();
    }

    /**
     * The (successes, trials) pair the two-proportion z-test measures.
     * Opens are measured against DELIVERED; click-to-open against UNIQUE
     * OPENS — that is what makes CTOR a proportion at all. Conversions are
     * measured against DELIVERED, and the numerator is CONVERTERS (distinct
     * subscribers), not conversion events: a z-test needs a proportion, and
     * one shopper buying twice is one converted recipient.
     *
     * @return array{0: string, 1: string} stats keys
     */
    public function proportion(): array
    {
        return match ($this) {
            self::UniqueOpens => ['unique_opens', 'delivered'],
            self::UniqueClicks => ['unique_clicks', 'delivered'],
            self::ClickToOpen => ['unique_clicks', 'unique_opens'],
            self::Conversions => ['converters', 'delivered'],
        };
    }

    /** @return array<string, string> value => label — available criteria only */
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
