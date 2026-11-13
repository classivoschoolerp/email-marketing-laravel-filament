<?php

declare(strict_types=1);

namespace App\Services\Campaigns\AbTest;

use App\Enums\AbTestVariable;
use App\Models\Campaign;
use App\Models\CampaignVariant;

/**
 * Variant bookkeeping for the §2.3.6 composer (F-2.3.6-001/002/012).
 *
 * The side-by-side composer edits variants as one repeater of parallel panes
 * with the traffic split in the same panel; this class owns everything that
 * must stay true regardless of what the panel posted: 2–8 variants, contiguous
 * A..H labels, positions 0..n−1, weights ≥ 1, and override columns cleared for
 * variables the test does not actually vary (so turning "content" off cannot
 * leave a stale per-variant body behind).
 */
final class AbTestManager
{
    public const MIN_VARIANTS = 2;

    public const MAX_VARIANTS = 8;

    /** Seed A/B from the campaign itself so the composer is never empty. */
    public function ensureVariants(Campaign $campaign): void
    {
        $existing = $campaign->variants()->count();

        for ($position = $existing; $position < self::MIN_VARIANTS; $position++) {
            $campaign->variants()->create([
                'position' => $position,
                'label' => CampaignVariant::labelForPosition($position),
                'subject' => $campaign->subject,
                'from_name' => $campaign->from_name,
                'preheader' => $campaign->preheader,
                'weight' => 1,
            ]);
        }

        $campaign->unsetRelation('variants');
    }

    /**
     * Re-establish every invariant after a save. Safe to call repeatedly.
     */
    public function normalize(Campaign $campaign): void
    {
        $variants = $campaign->variants()->orderBy('position')->orderBy('id')->get();

        // Trim beyond the cap (F-2.3.6-002) — never silently keep a 9th.
        foreach ($variants->slice(self::MAX_VARIANTS) as $extra) {
            $extra->delete();
        }

        $variants = $variants->take(self::MAX_VARIANTS)->values();
        $active = $this->activeColumns($campaign);

        foreach ($variants as $position => $variant) {
            $attributes = [
                'position' => $position,
                'label' => CampaignVariant::labelForPosition($position),
                'weight' => max(1, (int) $variant->weight),
            ];

            foreach ($this->allOverridableColumns() as $column) {
                if (! in_array($column, $active, true)) {
                    $attributes[$column] = $column === 'send_delay_minutes' ? 0 : null;
                }
            }

            $variant->forceFill($attributes)->save();
        }

        $campaign->unsetRelation('variants');
    }

    /**
     * Blocking problems with the A/B configuration, for CampaignPreflight.
     *
     * @return list<string>
     */
    public function problems(Campaign $campaign): array
    {
        if (! $campaign->ab_enabled) {
            return [];
        }

        $problems = [];
        $count = $campaign->variants()->count();

        if ($count < self::MIN_VARIANTS || $count > self::MAX_VARIANTS) {
            $problems[] = __('campaigns.ab.errors.variant_count', [
                'min' => self::MIN_VARIANTS,
                'max' => self::MAX_VARIANTS,
            ]);
        }

        if ($this->variables($campaign) === []) {
            $problems[] = __('campaigns.ab.errors.no_variable');
        }

        $percent = (int) $campaign->ab_test_percent;

        if ($percent < 1 || $percent > 100) {
            $problems[] = __('campaigns.ab.errors.percent_range');
        }

        return $problems;
    }

    /**
     * Test variables actually enabled on the campaign.
     *
     * @return list<AbTestVariable>
     */
    public function variables(Campaign $campaign): array
    {
        $out = [];

        foreach ((array) ($campaign->ab_variables ?? []) as $value) {
            $variable = AbTestVariable::tryFrom((string) $value);

            if ($variable !== null) {
                $out[] = $variable;
            }
        }

        return $out;
    }

    /**
     * Variant columns the enabled variables own.
     *
     * @return list<string>
     */
    public function activeColumns(Campaign $campaign): array
    {
        $columns = [];

        foreach ($this->variables($campaign) as $variable) {
            foreach ($variable->columns() as $column) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    /** @return list<string> */
    private function allOverridableColumns(): array
    {
        $columns = [];

        foreach (AbTestVariable::cases() as $variable) {
            foreach ($variable->columns() as $column) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Variants ready for the send path: only when the test is properly
     * configured. An empty list means "send the campaign normally".
     *
     * @return list<CampaignVariant>
     */
    public function sendableVariants(Campaign $campaign): array
    {
        if (! $campaign->ab_enabled || $this->problems($campaign) !== []) {
            return [];
        }

        return $campaign->variants()->orderBy('position')->orderBy('id')->get()->all();
    }
}
