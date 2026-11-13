<?php

declare(strict_types=1);

namespace App\Services\Campaigns\AbTest;

use App\Models\Campaign;
use App\Models\CampaignVariant;
use Illuminate\Support\Collection;

/**
 * Splits a campaign's recipients into the test group and its variants
 * (spec §2.3.6 F-2.3.6-005 / F-2.3.6-012).
 *
 * DETERMINISM is the whole point: a resume wave, a retried job or a worker
 * that died mid-batch must re-derive exactly the same assignment, otherwise a
 * subscriber could be counted against one variant and mailed another.
 *
 *  1. Every candidate gets a stable bucket key
 *         xxh128( campaign_uid . ':' . subscriber_uid . ':ab' )
 *     — the same hash family D-55 already uses for spintax seeding — and the
 *     candidates are ordered by it. This is a pseudo-random but reproducible
 *     shuffle, so the test group is not biased towards the oldest signups the
 *     way `ORDER BY id LIMIT n` would be.
 *
 *  2. The first `round(total × percent / 100)` of that order is the test
 *     group (at least one recipient per variant when the percentage rounds
 *     down to nothing — a test group smaller than the variant count cannot
 *     test anything).
 *
 *  3. The test group is apportioned across the variants by weight using the
 *     LARGEST REMAINDER method: variant i takes floor(N·wᵢ/W) seats, then the
 *     leftover seats go to the largest fractional parts (ties broken by
 *     position, so the result is total-order deterministic). The seat counts
 *     always sum to exactly N — no recipient is dropped or double-assigned.
 *
 * The remainder (everyone outside the test group) is never returned here: it
 * is simply "not in the test wave", and the winner phase picks it up because
 * those subscribers still have no delivery-log row.
 */
final class AbTestSplitter
{
    /**
     * Size of the test group for a total audience (F-2.3.6-005).
     * `$variantCount` floors the result so every variant can get someone.
     */
    public function testGroupSize(int $total, int $percent, int $variantCount): int
    {
        if ($total < 1 || $variantCount < 1) {
            return 0;
        }

        $percent = max(0, min(100, $percent));
        $size = (int) round($total * $percent / 100);

        return max(min($total, $variantCount), min($total, $size));
    }

    /**
     * Order candidates deterministically. `$rows` is any collection of objects
     * exposing a `uid` property (the send wave passes subscriber id/uid stubs).
     *
     * @template TRow of object
     *
     * @param  Collection<int, TRow>  $rows
     * @return Collection<int, TRow>
     */
    public function shuffleDeterministically(Campaign $campaign, Collection $rows): Collection
    {
        $uid = (string) $campaign->uid;

        return $rows
            ->sortBy(fn (object $row): string => hash('xxh128', $uid.':'.((string) $row->uid).':ab'), SORT_STRING)
            ->values();
    }

    /**
     * Apportion `$size` seats across variants by weight (largest remainder).
     *
     * @param  list<CampaignVariant>  $variants
     * @return array<int, int> variant id => seats (sums to $size)
     */
    public function seats(array $variants, int $size): array
    {
        $seats = [];

        if ($variants === [] || $size < 1) {
            foreach ($variants as $variant) {
                $seats[(int) $variant->id] = 0;
            }

            return $seats;
        }

        $totalWeight = 0;

        foreach ($variants as $variant) {
            $totalWeight += max(1, (int) $variant->weight);
        }

        $remainders = [];
        $assigned = 0;

        foreach ($variants as $index => $variant) {
            $exact = $size * max(1, (int) $variant->weight) / $totalWeight;
            $floor = (int) floor($exact);

            $seats[(int) $variant->id] = $floor;
            $assigned += $floor;
            $remainders[] = ['id' => (int) $variant->id, 'frac' => $exact - $floor, 'index' => $index];
        }

        // Leftover seats to the largest fractional parts; position breaks ties.
        usort($remainders, static function (array $a, array $b): int {
            return $b['frac'] <=> $a['frac'] ?: $a['index'] <=> $b['index'];
        });

        $leftover = $size - $assigned;

        for ($i = 0; $i < $leftover; $i++) {
            $seats[$remainders[$i % count($remainders)]['id']]++;
        }

        return $seats;
    }

    /**
     * Full split: which rows form the test group, and which variant each row
     * belongs to.
     *
     * @template TRow of object
     *
     * @param  Collection<int, TRow>  $rows  candidate recipients (id + uid)
     * @param  list<CampaignVariant>  $variants
     * @return array{group: Collection<int, TRow>, assignments: array<int, int>} assignments: row id => variant id
     */
    public function split(Campaign $campaign, Collection $rows, array $variants): array
    {
        $ordered = $this->shuffleDeterministically($campaign, $rows);

        $size = $this->testGroupSize(
            $ordered->count(),
            (int) $campaign->ab_test_percent,
            count($variants),
        );

        /** @var Collection<int, TRow> $group */
        $group = $ordered->take($size)->values();
        $seats = $this->seats($variants, $group->count());

        $assignments = [];
        $cursor = 0;

        foreach ($variants as $variant) {
            $take = $seats[(int) $variant->id] ?? 0;

            for ($i = 0; $i < $take; $i++) {
                $row = $group->get($cursor);

                if ($row === null) {
                    break;
                }

                $assignments[(int) $row->id] = (int) $variant->id;
                $cursor++;
            }
        }

        return ['group' => $group, 'assignments' => $assignments];
    }
}
