<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormVariant;
use App\Services\Campaigns\AbTest\SignificanceTest;

/**
 * The A/B read-out of a §2.7 form (spec F-2.7-013): views, submissions and
 * conversion rate per arm, plus a confidence figure for each challenger
 * against the baseline.
 *
 * THE MATHS IS NOT REIMPLEMENTED HERE. The product already owns exactly one
 * two-proportion z-test — {@see SignificanceTest}, documented line by line in
 * §2.3.6 — and this class calls it with (submissions, views) per arm. A third
 * z-test in the codebase would be a third chance to get a factual claim about
 * a customer's traffic wrong, and would let two screens disagree about the
 * same numbers. {@see SignificanceTest::MIN_TRIALS} therefore also governs
 * forms: below 30 views in either arm the answer is "not computable", not a
 * confident-looking number derived from four impressions.
 *
 * The baseline is, in order: the arm flagged `is_control`; else the arm with
 * the lowest id (the first one created). Every challenger is compared against
 * that single baseline, which is a pairwise test and not a
 * multiple-comparison-corrected family — the same honest limit §2.3.6 states.
 */
class FormAbTest
{
    /** The threshold the UI calls "significant". */
    public const CONFIDENCE_LEVEL = 95.0;

    public function __construct(
        private readonly FormAnalytics $analytics,
        private readonly SignificanceTest $significance,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     baseline_id: int|null,
     *     winner_id: int|null,
     *     level: float,
     *     arms: list<array{
     *         id:int, label:string, weight:int, is_control:bool, is_winner:bool,
     *         enabled:bool, is_baseline:bool, views:int, submissions:int,
     *         conversion_rate:float, confidence:float|null, significant:bool
     *     }>
     * }
     */
    public function report(Form $form): array
    {
        /** @var list<FormVariant> $variants */
        $variants = FormVariant::withoutTenancy()
            ->where('form_id', $form->id)
            ->orderBy('id')
            ->get()
            ->all();

        // A form that has never had an arm has no A/B report — showing an
        // "Original" row on its own would put a test read-out on every form in
        // the product and mean nothing.
        if ($variants === []) {
            return [
                'enabled' => (bool) $form->ab_enabled,
                'baseline_id' => null,
                'winner_id' => null,
                'level' => self::CONFIDENCE_LEVEL,
                'arms' => [],
            ];
        }

        $counts = [];

        foreach ($this->analytics->byVariant($form) as $row) {
            $counts[$row['form_variant_id']] = $row;
        }

        $baselineId = $this->baselineId($variants);
        $arms = [];

        // The "no arm" bucket only appears when it actually carries traffic:
        // showing an empty Original row on a form that has always been tested
        // would be noise, hiding a non-empty one would break the totals.
        $original = $counts[FormAnalytics::NO_VARIANT] ?? null;

        if ($original !== null && ($original['views'] > 0 || $original['submissions'] > 0)) {
            $arms[] = $this->arm(
                FormAnalytics::NO_VARIANT,
                __('forms.ab.original'),
                0,
                false,
                false,
                true,
                $baselineId === null,
                $original,
            );
        }

        foreach ($variants as $variant) {
            $id = (int) $variant->id;
            $arms[] = $this->arm(
                $id,
                (string) $variant->name,
                (int) $variant->weight,
                (bool) $variant->is_control,
                (bool) $variant->is_winner,
                (bool) $variant->enabled,
                $id === $baselineId,
                $counts[$id] ?? ['views' => 0, 'submissions' => 0, 'conversion_rate' => 0.0],
            );
        }

        $baseline = null;

        foreach ($arms as $arm) {
            if ($arm['is_baseline']) {
                $baseline = $arm;
            }
        }

        if ($baseline !== null) {
            foreach ($arms as $index => $arm) {
                // The "no arm" bucket is pre-test traffic, never a challenger:
                // comparing it against an arm would be comparing two different
                // time windows and calling the difference an effect.
                if ($arm['is_baseline'] || $arm['id'] === FormAnalytics::NO_VARIANT) {
                    continue;
                }

                $result = $this->significance->compare(
                    $arm['submissions'],
                    $arm['views'],
                    $baseline['submissions'],
                    $baseline['views'],
                );

                $arms[$index]['confidence'] = $result->computable ? $result->confidencePercent() : null;
                $arms[$index]['significant'] = $result->isSignificantAt(self::CONFIDENCE_LEVEL);
            }
        }

        return [
            'enabled' => (bool) $form->ab_enabled,
            'baseline_id' => $baseline === null ? null : $baseline['id'],
            'winner_id' => $this->winnerId($variants),
            'level' => self::CONFIDENCE_LEVEL,
            'arms' => $arms,
        ];
    }

    /**
     * @param  list<FormVariant>  $variants
     */
    private function baselineId(array $variants): ?int
    {
        foreach ($variants as $variant) {
            if ($variant->is_control) {
                return (int) $variant->id;
            }
        }

        return $variants === [] ? null : (int) $variants[0]->id;
    }

    /**
     * @param  list<FormVariant>  $variants
     */
    private function winnerId(array $variants): ?int
    {
        foreach ($variants as $variant) {
            if ($variant->is_winner) {
                return (int) $variant->id;
            }
        }

        return null;
    }

    /**
     * @param  array{views:int, submissions:int, conversion_rate:float}  $counts
     * @return array{id:int, label:string, weight:int, is_control:bool, is_winner:bool, enabled:bool, is_baseline:bool, views:int, submissions:int, conversion_rate:float, confidence:float|null, significant:bool}
     */
    private function arm(
        int $id,
        string $label,
        int $weight,
        bool $isControl,
        bool $isWinner,
        bool $enabled,
        bool $isBaseline,
        array $counts,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'weight' => $weight,
            'is_control' => $isControl,
            'is_winner' => $isWinner,
            'enabled' => $enabled,
            'is_baseline' => $isBaseline,
            'views' => (int) $counts['views'],
            'submissions' => (int) $counts['submissions'],
            'conversion_rate' => (float) $counts['conversion_rate'],
            'confidence' => null,
            'significant' => false,
        ];
    }
}
