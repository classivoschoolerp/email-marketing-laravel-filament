<?php

declare(strict_types=1);

namespace App\Services\Campaigns\AbTest;

use App\Models\Campaign;

/**
 * Per-FACTOR effects for a multivariate test (spec §2.3.6 F-2.3.6-004, WP-46).
 *
 * The per-variant table (F-2.3.6-009) answers "which of these eight emails
 * won". A factorial design can answer the more useful question — "does subject
 * B win regardless of the image?" — by MARGINALISING: every variant carrying
 * subject B is pooled, across all image levels, and compared with the pool
 * carrying subject A. That is what {@see effects()} computes.
 *
 * ============ THE MULTIPLE-COMPARISON PROBLEM, ADDRESSED ============
 * SignificanceTest documents an honest limit: leader-vs-runner-up is ONE
 * pairwise test with no correction, which is defensible for a single pair and
 * indefensible here. A 2×2×2 design produces one comparison per non-best level
 * per factor, plus the variant-level leader test — a family of tests, and at
 * α = 0.05 a family of 5 independent tests has a ~23 % chance of at least one
 * false positive (1 − 0.95⁵).
 *
 * This class therefore applies HOLM–BONFERRONI (Holm 1979), the step-down
 * procedure that controls the family-wise error rate without Bonferroni's
 * conservatism:
 *
 *   1. order the m raw p-values ascending: p₍₁₎ ≤ p₍₂₎ ≤ … ≤ p₍ₘ₎;
 *   2. reject H₍ᵢ₎ iff p₍ᵢ₎ ≤ α / (m − i + 1) AND every earlier hypothesis
 *      was rejected — the first failure stops the procedure and everything
 *      after it is retained.
 *
 * Holm is uniformly more powerful than Bonferroni and, unlike it, needs no
 * independence assumption — which matters because the marginal pools of one
 * factor share recipients with those of another.
 *
 * BOTH numbers are reported: `significant` (Holm-corrected, the one the UI
 * leads with) and `significant_uncorrected` (the raw pairwise verdict), so the
 * customer can see exactly what the correction cost them rather than being
 * shown one number with no provenance. The report caption states the family
 * size in as many words.
 */
final class MultivariateAnalysis
{
    public function __construct(
        private readonly AbTestStats $stats,
        private readonly SignificanceTest $significance,
        private readonly MultivariateDesign $design,
    ) {}

    /**
     * @return array{
     *     available: bool,
     *     criterion: string,
     *     alpha: float,
     *     comparisons: int,
     *     factors: list<array{
     *         variable: string, label: string,
     *         levels: list<array{
     *             level: string, variants: list<string>,
     *             successes: int, trials: int, rate: float, is_best: bool,
     *             computable: bool, p_value: ?float, holm_threshold: ?float,
     *             significant: bool, significant_uncorrected: bool
     *         }>
     *     }>
     * }
     */
    public function effects(Campaign $campaign): array
    {
        $empty = [
            'available' => false,
            'criterion' => (string) $campaign->ab_winner_criteria,
            'alpha' => $this->alpha($campaign),
            'comparisons' => 0,
            'factors' => [],
        ];

        if (! $campaign->ab_enabled || ! $this->design->isFactorial($campaign)) {
            return $empty;
        }

        $criteria = $this->stats->criteria($campaign);

        if (! $criteria->isAvailable()) {
            return $empty;
        }

        [$successKey, $trialKey] = $criteria->proportion();

        // Pool every variant into its (factor, level) cell.
        $pools = [];

        foreach ($campaign->variants as $variant) {
            $row = $this->stats->row($campaign, $variant, $criteria);

            foreach ((array) ($variant->factors ?? []) as $variable => $level) {
                $key = (string) $variable;
                $value = (string) $level;

                $pools[$key][$value]['successes'] = ($pools[$key][$value]['successes'] ?? 0) + (int) ($row[$successKey] ?? 0);
                $pools[$key][$value]['trials'] = ($pools[$key][$value]['trials'] ?? 0) + (int) ($row[$trialKey] ?? 0);
                $pools[$key][$value]['variants'][] = (string) $variant->label;
            }
        }

        // Best level per factor, then one comparison per remaining level.
        $factors = [];
        $family = [];

        foreach ($pools as $variable => $levels) {
            $rows = [];

            foreach ($levels as $level => $cell) {
                $trials = (int) $cell['trials'];
                $successes = (int) $cell['successes'];

                $rows[] = [
                    'level' => (string) $level,
                    'variants' => $cell['variants'],
                    'successes' => $successes,
                    'trials' => $trials,
                    'rate' => $trials > 0 ? round($successes * 100 / $trials, 2) : 0.0,
                ];
            }

            // Highest rate wins; ties break on raw successes, then on the
            // level string, so the ordering is total and reproducible.
            usort($rows, static fn (array $a, array $b): int => $b['rate'] <=> $a['rate']
                ?: $b['successes'] <=> $a['successes']
                ?: strcmp($a['level'], $b['level']));

            $best = $rows[0];

            foreach ($rows as $index => $row) {
                $rows[$index]['is_best'] = $index === 0;
                $rows[$index]['computable'] = false;
                $rows[$index]['p_value'] = null;
                $rows[$index]['holm_threshold'] = null;
                $rows[$index]['significant'] = false;
                $rows[$index]['significant_uncorrected'] = false;

                if ($index === 0) {
                    continue;
                }

                $result = $this->significance->compare(
                    $best['successes'],
                    $best['trials'],
                    $row['successes'],
                    $row['trials'],
                );

                $rows[$index]['computable'] = $result->computable;

                if ($result->computable) {
                    $rows[$index]['p_value'] = $result->pValue;
                    $family[] = ['variable' => (string) $variable, 'index' => $index, 'p' => $result->pValue];
                }
            }

            $factors[(string) $variable] = [
                'variable' => (string) $variable,
                'label' => $this->design->label((string) $variable),
                'levels' => $rows,
            ];
        }

        $alpha = $this->alpha($campaign);
        $m = count($family);

        // Holm–Bonferroni step-down over the whole family.
        usort($family, static fn (array $a, array $b): int => $a['p'] <=> $b['p']);

        $stillRejecting = true;

        foreach ($family as $rank => $test) {
            $threshold = $m > 0 ? $alpha / ($m - $rank) : $alpha;
            $reject = $stillRejecting && $test['p'] <= $threshold;

            if (! $reject) {
                $stillRejecting = false;
            }

            $factors[$test['variable']]['levels'][$test['index']]['holm_threshold'] = round($threshold, 8);
            $factors[$test['variable']]['levels'][$test['index']]['significant'] = $reject;
            $factors[$test['variable']]['levels'][$test['index']]['significant_uncorrected'] = $test['p'] <= $alpha;
        }

        return [
            'available' => true,
            'criterion' => (string) $campaign->ab_winner_criteria,
            'alpha' => $alpha,
            'comparisons' => $m,
            'factors' => array_values($factors),
        ];
    }

    /**
     * α = 1 − level/100, the same threshold SignificanceResult uses. Rounded
     * because 1 − 95/100 is 0.050000000000000044 in binary floating point, and
     * a threshold printed to the customer as "0.05" must BE 0.05.
     */
    private function alpha(Campaign $campaign): float
    {
        return round(max(0.0, 1.0 - (float) $campaign->ab_confidence_level / 100), 10);
    }
}
