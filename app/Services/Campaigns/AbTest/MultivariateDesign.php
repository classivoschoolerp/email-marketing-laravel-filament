<?php

declare(strict_types=1);

namespace App\Services\Campaigns\AbTest;

use App\Enums\AbTestVariable;
use App\Models\Campaign;
use App\Models\CampaignVariant;
use InvalidArgumentException;

/**
 * Multivariate combinations (spec §2.3.6 F-2.3.6-004, WP-46).
 *
 * WHAT CHANGED. Until now, selecting several A/B variables meant "each variant
 * carries its own value for each" — three variants each with their own subject
 * AND their own image, which cannot separate the two effects. This class turns
 * a set of FACTORS with LEVELS into the full factorial cross-product:
 *
 *     subject × image  =  2 levels × 2 levels  →  4 variants
 *       A: (subject 1, image 1)   B: (subject 1, image 2)
 *       C: (subject 2, image 1)   D: (subject 2, image 2)
 *
 * Every generated variant records its level map in `campaign_variants.factors`,
 * which is what lets {@see MultivariateAnalysis} answer "subject 2 wins
 * regardless of the image" instead of only "variant C won".
 *
 * ===================== THE SIZE LIMIT IS A REFUSAL =====================
 * A factorial design multiplies. {@see AbTestManager::MAX_VARIANTS} is 8, so
 * 2×2 (4) and 2×2×2 (8) fit, while 3×3×2 = 18 DOES NOT. An oversized design is
 * REFUSED with the arithmetic spelled out — never truncated to the first eight
 * cells, because a truncated factorial is not a factorial: the missing cells
 * would silently bias every per-factor average computed from it.
 *
 * The traffic split is untouched: {@see AbTestSplitter} apportions the test
 * group across whatever variants exist, so a 2×2×2 design is eight equal arms
 * allocated by the same deterministic largest-remainder seats as before.
 */
final class MultivariateDesign
{
    /** A factor with one level is not a factor. */
    public const MIN_LEVELS = 2;

    /** More levels than this cannot fit any design under MAX_VARIANTS. */
    public const MAX_LEVELS = AbTestManager::MAX_VARIANTS;

    public function __construct(private readonly AbTestManager $manager) {}

    /**
     * Number of variants a design would produce (the product of its level
     * counts). Zero when there are no factors.
     *
     * @param  array<string, list<mixed>>  $factors  variable value => levels
     */
    public function size(array $factors): int
    {
        $factors = $this->clean($factors);

        if ($factors === []) {
            return 0;
        }

        $size = 1;

        foreach ($factors as $levels) {
            $size *= count($levels);
        }

        return $size;
    }

    /**
     * Blocking problems with a proposed design, in the customer's language.
     * Empty means {@see generate()} will succeed.
     *
     * @param  array<string, list<mixed>>  $factors
     * @return list<string>
     */
    public function problems(array $factors): array
    {
        $problems = [];
        $clean = $this->clean($factors);

        if ($clean === []) {
            return [__('campaigns.ab.errors.no_variable')];
        }

        foreach ($clean as $variable => $levels) {
            if (count($levels) < self::MIN_LEVELS) {
                $problems[] = __('campaigns.mvt.errors.too_few_levels', [
                    'factor' => $this->label($variable),
                    'min' => self::MIN_LEVELS,
                ]);
            }
        }

        $size = $this->size($clean);

        if ($size > AbTestManager::MAX_VARIANTS) {
            $problems[] = __('campaigns.mvt.errors.too_many_combinations', [
                'design' => $this->designNotation($clean),
                'size' => $size,
                'max' => AbTestManager::MAX_VARIANTS,
            ]);
        }

        if ($problems === [] && $size < AbTestManager::MIN_VARIANTS) {
            $problems[] = __('campaigns.ab.errors.variant_count', [
                'min' => AbTestManager::MIN_VARIANTS,
                'max' => AbTestManager::MAX_VARIANTS,
            ]);
        }

        return $problems;
    }

    /**
     * The cross-product, in a stable order: the LAST factor varies fastest,
     * exactly like an odometer, so a design is reproducible and a customer
     * reading the variant list sees the levels grouped.
     *
     * @param  array<string, list<mixed>>  $factors
     * @return list<array<string, mixed>> one level map per combination
     */
    public function combinations(array $factors): array
    {
        $factors = $this->clean($factors);

        if ($factors === []) {
            return [];
        }

        $combinations = [[]];

        foreach ($factors as $variable => $levels) {
            $next = [];

            foreach ($combinations as $partial) {
                foreach ($levels as $level) {
                    $next[] = $partial + [$variable => $level];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * Replace the campaign's variants with the factorial design.
     *
     * @param  array<string, list<mixed>>  $factors
     * @return list<CampaignVariant>
     *
     * @throws InvalidArgumentException when {@see problems()} is not empty
     */
    public function generate(Campaign $campaign, array $factors): array
    {
        $problems = $this->problems($factors);

        if ($problems !== []) {
            throw new InvalidArgumentException(implode(' · ', $problems));
        }

        $factors = $this->clean($factors);
        $combinations = $this->combinations($factors);

        $campaign->variants()->delete();

        $created = [];

        foreach ($combinations as $position => $levels) {
            $attributes = [
                'position' => $position,
                'label' => CampaignVariant::labelForPosition($position),
                'weight' => 1,
                'factors' => $levels,
            ];

            foreach ($levels as $variable => $level) {
                $attributes[$this->columnFor($variable)] = $level;
            }

            $created[] = $campaign->variants()->create($attributes);
        }

        $campaign->forceFill([
            'ab_enabled' => true,
            'ab_variables' => array_keys($factors),
        ])->save();

        $campaign->unsetRelation('variants');

        // Re-establish every AbTestManager invariant (labels, positions,
        // weights, and clearing columns for variables not under test).
        $this->manager->normalize($campaign);

        return $campaign->variants()->orderBy('position')->get()->all();
    }

    /** True when this campaign's variants were generated as a factorial design. */
    public function isFactorial(Campaign $campaign): bool
    {
        foreach ($campaign->variants as $variant) {
            if (! is_array($variant->factors) || $variant->factors === []) {
                return false;
            }
        }

        return $campaign->variants->count() >= AbTestManager::MIN_VARIANTS;
    }

    /**
     * Factor => levels actually present on the campaign's variants.
     *
     * @return array<string, list<string>>
     */
    public function factorsOf(Campaign $campaign): array
    {
        $factors = [];

        foreach ($campaign->variants as $variant) {
            foreach ((array) ($variant->factors ?? []) as $variable => $level) {
                $value = (string) $level;

                if (! in_array($value, $factors[(string) $variable] ?? [], true)) {
                    $factors[(string) $variable][] = $value;
                }
            }
        }

        return $factors;
    }

    /** "2 × 2 × 3" — the arithmetic the refusal message quotes back. */
    public function designNotation(array $factors): string
    {
        $factors = $this->clean($factors);

        return implode(' × ', array_map(
            static fn (array $levels): string => (string) count($levels),
            $factors,
        ));
    }

    public function label(string $variable): string
    {
        return AbTestVariable::tryFrom($variable)?->label() ?? $variable;
    }

    /* ---------------------------------------------------------------- */

    /**
     * The single variant column a factor's level is written to. Content spans
     * three columns; the level is the HTML body, which is the one a customer
     * actually authors per cell.
     */
    public function columnFor(string $variable): string
    {
        return match (AbTestVariable::tryFrom($variable)) {
            AbTestVariable::Subject => 'subject',
            AbTestVariable::FromName => 'from_name',
            AbTestVariable::Preheader => 'preheader',
            AbTestVariable::Content => 'content_html',
            AbTestVariable::SendTime => 'send_delay_minutes',
            default => 'subject',
        };
    }

    /**
     * Drop unknown variables, empty levels and duplicate levels; preserve the
     * caller's factor order.
     *
     * @param  array<string, list<mixed>>  $factors
     * @return array<string, list<mixed>>
     */
    private function clean(array $factors): array
    {
        $clean = [];

        foreach ($factors as $variable => $levels) {
            if (AbTestVariable::tryFrom((string) $variable) === null) {
                continue;
            }

            $values = [];

            foreach ((array) $levels as $level) {
                $value = is_string($level) ? trim($level) : $level;

                if ($value === '' || $value === null) {
                    continue;
                }

                if (! in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }

            if ($values !== []) {
                $clean[(string) $variable] = $values;
            }
        }

        return $clean;
    }
}
