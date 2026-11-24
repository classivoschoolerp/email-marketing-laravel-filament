<?php

declare(strict_types=1);

namespace App\Services\Landing;

use App\Models\LandingPage;
use App\Models\LandingPageConversion;
use App\Models\LandingPageVariant;
use App\Models\LandingPageVisit;

/**
 * Landing-page reporting (spec §2.6 F-2.6-008) and the A/B comparison behind
 * winner selection (F-2.6-004).
 *
 * The significance number is a real two-proportion z-test, not a bar that
 * fills up: conversion rate is a proportion, the arms are independent
 * samples, so the pooled-variance z-test is the textbook instrument.
 * `confidence` is the two-tailed 1 − p value — 0.95 means "a difference this
 * large would show up by chance in 5% of tests with no real effect".
 *
 * It is reported honestly, which means it is reported as NOT significant far
 * more often than a marketing dashboard would like: with a handful of visits
 * no arm can win, and this class says so instead of crowning the arm that is
 * ahead. `SIGNIFICANT_AT` is the threshold the UI colours on; the raw
 * confidence is always returned so the operator can judge for themselves.
 */
class LandingPageStats
{
    public const SIGNIFICANT_AT = 0.95;

    /** Below this many visits per arm, a z-test says nothing worth showing. */
    public const MIN_SAMPLE = 30;

    /**
     * Lifetime totals for a page.
     *
     * @return array{visits:int, unique_visitors:int, conversions:int, conversion_rate:float}
     */
    public function summary(LandingPage $page): array
    {
        $visits = LandingPageVisit::withoutTenancy()->where('landing_page_id', $page->id);

        return $this->shape(
            (int) $visits->clone()->count(),
            (int) $visits->clone()->distinct()->count('visitor_key'),
            (int) LandingPageConversion::withoutTenancy()->where('landing_page_id', $page->id)->count(),
        );
    }

    /**
     * Per-source breakdown, best converting first (F-2.6-008 "source").
     *
     * @return list<array{source:string, visits:int, unique_visitors:int, conversions:int, conversion_rate:float}>
     */
    public function bySource(LandingPage $page): array
    {
        $conversions = LandingPageConversion::withoutTenancy()
            ->where('landing_page_id', $page->id)
            ->groupBy('source')
            ->selectRaw('source, COUNT(*) as total')
            ->pluck('total', 'source');

        $rows = LandingPageVisit::withoutTenancy()
            ->where('landing_page_id', $page->id)
            ->groupBy('source')
            ->selectRaw('source, COUNT(*) as visits, COUNT(DISTINCT visitor_key) as uniques')
            ->get()
            ->map(function (object $row) use ($conversions): array {
                $source = (string) $row->source;

                return ['source' => $source] + $this->shape(
                    (int) $row->visits,
                    (int) $row->uniques,
                    (int) ($conversions[$source] ?? 0),
                );
            })
            ->all();

        usort($rows, static fn (array $a, array $b): int => $b['conversions'] <=> $a['conversions']);

        return $rows;
    }

    /**
     * Per-variant totals, plus the comparison against the control arm.
     *
     * The control is the arm flagged `is_control`, else the lowest-id arm —
     * every other arm is compared against it, which is what makes "uplift"
     * mean something.
     *
     * @return list<array{
     *     variant: LandingPageVariant,
     *     visits:int, unique_visitors:int, conversions:int, conversion_rate:float,
     *     is_control:bool, uplift:float|null, confidence:float|null, significant:bool
     * }>
     */
    public function variantComparison(LandingPage $page): array
    {
        $variants = LandingPageVariant::withoutTenancy()
            ->where('landing_page_id', $page->id)
            ->orderBy('id')
            ->get();

        if ($variants->isEmpty()) {
            return [];
        }

        $rows = [];

        foreach ($variants as $variant) {
            $visits = LandingPageVisit::withoutTenancy()->where('landing_page_variant_id', $variant->id);

            $rows[] = [
                'variant' => $variant,
                'is_control' => (bool) $variant->is_control,
            ] + $this->shape(
                (int) $visits->clone()->count(),
                (int) $visits->clone()->distinct()->count('visitor_key'),
                (int) LandingPageConversion::withoutTenancy()
                    ->where('landing_page_variant_id', $variant->id)->count(),
            );
        }

        $controlIndex = 0;

        foreach ($rows as $index => $row) {
            if ($row['is_control']) {
                $controlIndex = $index;

                break;
            }
        }

        $control = $rows[$controlIndex];

        foreach ($rows as $index => $row) {
            if ($index === $controlIndex) {
                $rows[$index] += ['uplift' => null, 'confidence' => null, 'significant' => false];

                continue;
            }

            $confidence = $this->confidence(
                $control['visits'],
                $control['conversions'],
                $row['visits'],
                $row['conversions'],
            );

            $rows[$index] += [
                'uplift' => $control['conversion_rate'] <= 0.0
                    ? null
                    : round((($row['conversion_rate'] - $control['conversion_rate']) / $control['conversion_rate']) * 100, 2),
                'confidence' => $confidence,
                'significant' => $confidence !== null && $confidence >= self::SIGNIFICANT_AT,
            ];
        }

        return $rows;
    }

    /**
     * The arm the data supports as winner, or NULL when the data does not
     * support one. Never returns "whichever is ahead".
     */
    public function suggestedWinner(LandingPage $page): ?LandingPageVariant
    {
        $best = null;
        $bestRate = -1.0;

        foreach ($this->variantComparison($page) as $row) {
            if (! $row['significant'] || $row['conversion_rate'] <= $bestRate) {
                continue;
            }

            $best = $row['variant'];
            $bestRate = $row['conversion_rate'];
        }

        return $best;
    }

    /**
     * Two-tailed confidence that the two conversion rates differ.
     * NULL when either arm is below {@see MIN_SAMPLE} or the pooled variance
     * is zero (no conversions at all, or every visit converted in both arms).
     */
    public function confidence(int $visitsA, int $conversionsA, int $visitsB, int $conversionsB): ?float
    {
        if ($visitsA < self::MIN_SAMPLE || $visitsB < self::MIN_SAMPLE) {
            return null;
        }

        $rateA = $conversionsA / $visitsA;
        $rateB = $conversionsB / $visitsB;
        $pooled = ($conversionsA + $conversionsB) / ($visitsA + $visitsB);
        $variance = $pooled * (1 - $pooled) * (1 / $visitsA + 1 / $visitsB);

        if ($variance <= 0.0) {
            return null;
        }

        $z = abs($rateA - $rateB) / sqrt($variance);

        return round(2 * $this->normalCdf($z) - 1, 4);
    }

    /**
     * Φ(x) via Abramowitz & Stegun 7.1.26 (|ε| < 1.5e-7) — accurate far
     * beyond the two decimals a confidence percentage is ever read at, and
     * dependency-free (PHP has no erf() without ext-stats).
     */
    private function normalCdf(float $x): float
    {
        return 0.5 * (1.0 + $this->erf($x / sqrt(2.0)));
    }

    /** Abramowitz & Stegun 7.1.26, Horner-evaluated; odd, so sign folds out. */
    private function erf(float $x): float
    {
        $sign = $x < 0 ? -1.0 : 1.0;
        $x = abs($x);

        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $poly = ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t;

        return $sign * (1.0 - $poly * exp(-$x * $x));
    }

    /**
     * @return array{visits:int, unique_visitors:int, conversions:int, conversion_rate:float}
     */
    private function shape(int $visits, int $uniques, int $conversions): array
    {
        return [
            'visits' => $visits,
            'unique_visitors' => $uniques,
            'conversions' => $conversions,
            'conversion_rate' => $visits > 0 ? round(($conversions / $visits) * 100, 2) : 0.0,
        ];
    }
}
