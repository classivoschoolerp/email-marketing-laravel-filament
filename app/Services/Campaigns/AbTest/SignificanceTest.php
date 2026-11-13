<?php

declare(strict_types=1);

namespace App\Services\Campaigns\AbTest;

/**
 * Two-proportion z-test (spec §2.3.6 F-2.3.6-010 / F-2.3.6-011).
 *
 * THE MATHS, in full, because the winner of an A/B test is a factual claim.
 *
 * Two variants are compared on a rate: successes x out of trials n (e.g.
 * unique opens out of delivered). Under the null hypothesis both variants
 * share one true rate, so the best estimate of that shared rate is the POOLED
 * proportion
 *
 *     p̂ = (x₁ + x₂) / (n₁ + n₂)
 *
 * and the standard error of the difference of the two sample proportions is
 *
 *     SE = √( p̂ (1 − p̂) (1/n₁ + 1/n₂) )
 *
 * The test statistic is the difference in standard-error units
 *
 *     z = (p₁ − p₂) / SE
 *
 * which is approximately standard-normal for large n. The reported p-value is
 * TWO-TAILED — we are asking "are these different?", not "is A better?" —
 *
 *     p = 2 (1 − Φ(|z|))
 *
 * where Φ is the standard normal CDF, computed from the error function as
 * Φ(x) = ½(1 + erf(x/√2)). PHP ships no erf, so {@see erf()} uses the
 * Abramowitz & Stegun 7.1.26 rational-times-Gaussian approximation, whose
 * absolute error is bounded by 1.5·10⁻⁷ — three orders of magnitude finer
 * than any confidence threshold this product offers (90 / 95 / 99 %).
 *
 * CONFIDENCE THRESHOLD. "Statistically significant at 95 %" means p ≤ 0.05.
 * The campaign stores the level (`ab_confidence_level`, default 95) and
 * AbWinnerSelector declares a winner only once the leader beats the RUNNER-UP
 * at that level, so adding a third variant cannot manufacture a win.
 *
 * HONEST LIMITS, deliberately not papered over:
 *  - the normal approximation degrades when a cell expects fewer than ~5
 *    successes; {@see MIN_TRIALS} refuses to compute below that scale rather
 *    than returning a confident-looking number from four opens;
 *  - comparing the leader against only the runner-up is a single pairwise
 *    test, not a multiple-comparison-corrected family; with 8 variants the
 *    family-wise error rate is higher than the nominal level. The fixed
 *    decision window (F-2.3.6-007) remains available for anyone who wants a
 *    non-inferential rule.
 */
final class SignificanceTest
{
    /**
     * Below this many trials in either arm the normal approximation is not
     * trustworthy and the test reports "not computable" instead of a number.
     */
    public const MIN_TRIALS = 30;

    public function compare(int $successesA, int $trialsA, int $successesB, int $trialsB): SignificanceResult
    {
        $successesA = max(0, $successesA);
        $successesB = max(0, $successesB);
        $trialsA = max(0, $trialsA);
        $trialsB = max(0, $trialsB);

        $rateA = $trialsA > 0 ? $successesA / $trialsA : 0.0;
        $rateB = $trialsB > 0 ? $successesB / $trialsB : 0.0;

        if ($trialsA < self::MIN_TRIALS || $trialsB < self::MIN_TRIALS
            || $successesA > $trialsA || $successesB > $trialsB) {
            return new SignificanceResult($rateA, $rateB, 0.0, 1.0, computable: false);
        }

        $pooled = ($successesA + $successesB) / ($trialsA + $trialsB);
        $variance = $pooled * (1 - $pooled) * (1 / $trialsA + 1 / $trialsB);

        if ($variance <= 0.0) {
            // Both arms all-success or all-failure: zero observed variance,
            // so there is no evidence of a difference at all.
            return new SignificanceResult($rateA, $rateB, 0.0, 1.0, computable: false);
        }

        $z = ($rateA - $rateB) / sqrt($variance);
        $pValue = 2 * (1 - $this->normalCdf(abs($z)));

        return new SignificanceResult(
            rateA: $rateA,
            rateB: $rateB,
            z: $z,
            pValue: max(0.0, min(1.0, $pValue)),
            computable: true,
        );
    }

    /** Standard normal CDF Φ(x) = ½(1 + erf(x/√2)). */
    public function normalCdf(float $x): float
    {
        return 0.5 * (1 + $this->erf($x / M_SQRT2));
    }

    /**
     * Error function, Abramowitz & Stegun 7.1.26 (|ε| ≤ 1.5·10⁻⁷).
     *
     *     erf(x) ≈ 1 − (a₁t + a₂t² + a₃t³ + a₄t⁴ + a₅t⁵) e^(−x²),
     *     t = 1 / (1 + px),  x ≥ 0;   erf(−x) = −erf(x)
     */
    public function erf(float $x): float
    {
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);

        $p = 0.3275911;
        $a1 = 0.254829592;
        $a2 = -0.284496736;
        $a3 = 1.421413741;
        $a4 = -1.453152027;
        $a5 = 1.061405429;

        $t = 1 / (1 + $p * $x);
        $poly = ((((($a5 * $t) + $a4) * $t + $a3) * $t + $a2) * $t + $a1) * $t;

        return $sign * (1 - $poly * exp(-$x * $x));
    }
}
