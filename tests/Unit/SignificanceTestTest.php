<?php

declare(strict_types=1);

use App\Services\Campaigns\AbTest\SignificanceTest;

/*
 * §2.3.6 F-2.3.6-010/011 — the two-proportion z-test that decides an A/B
 * winner. Pure maths, no database: every expectation below is a textbook
 * value, so a regression in the erf approximation or the pooled-variance
 * formula fails here rather than silently mis-declaring a winner.
 */

beforeEach(function (): void {
    $this->test = new SignificanceTest;
});

// erf against published values (Abramowitz & Stegun 7.1.26, |ε| ≤ 1.5e-7).
it('computes the error function to the documented precision', function (): void {
    expect($this->test->erf(0.0))->toBeLessThan(1.0e-8)
        ->and($this->test->erf(0.5))->toBeGreaterThan(0.5204998 - 1e-6)
        ->and($this->test->erf(0.5))->toBeLessThan(0.5204999 + 1e-6)
        ->and($this->test->erf(1.0))->toBeGreaterThan(0.8427007 - 1e-6)
        ->and($this->test->erf(1.0))->toBeLessThan(0.8427008 + 1e-6)
        ->and($this->test->erf(-1.0))->toBeLessThan(-0.8427007 + 1e-6);
});

// Φ(1.96) = 0.9750021049 — the 95 % two-tailed critical value.
it('computes the standard normal CDF', function (): void {
    expect(round($this->test->normalCdf(1.96), 6))->toBe(0.975002)
        ->and(round($this->test->normalCdf(0.0), 6))->toBe(0.5)
        ->and(round($this->test->normalCdf(-1.96), 6))->toBe(0.024998);
});

// 100/1000 vs 150/1000: p̂ = 0.125, SE = 0.0147902, z = -3.3806, p = 0.000723.
it('rejects the null hypothesis for a clearly better variant', function (): void {
    $result = $this->test->compare(100, 1000, 150, 1000);

    expect($result->computable)->toBeTrue()
        ->and(round($result->z, 6))->toBe(-3.380617)
        ->and(round($result->pValue, 8))->toBe(0.00072334)
        ->and(round($result->confidencePercent(), 2))->toBe(99.93)
        ->and($result->isSignificantAt(95.0))->toBeTrue()
        ->and($result->isSignificantAt(99.0))->toBeTrue()
        ->and(round($result->rateA, 4))->toBe(0.1)
        ->and(round($result->rateB, 4))->toBe(0.15);
});

// 50/100 vs 55/100: z = -0.70799, p = 0.47895 — nowhere near significant.
it('does not reject the null hypothesis for a small difference', function (): void {
    $result = $this->test->compare(50, 100, 55, 100);

    expect($result->computable)->toBeTrue()
        ->and(round($result->z, 6))->toBe(-0.707992)
        ->and(round($result->pValue, 6))->toBe(0.478950)
        ->and($result->isSignificantAt(90.0))->toBeFalse()
        ->and($result->isSignificantAt(95.0))->toBeFalse();
});

// 300/1000 vs 260/1000: z = 1.99205, p = 0.046366 — significant at 95 %,
// NOT at 99 %. This is the case that proves the threshold is honoured.
it('respects the configured confidence level', function (): void {
    $result = $this->test->compare(300, 1000, 260, 1000);

    expect(round($result->z, 5))->toBe(1.99205)
        ->and(round($result->pValue, 6))->toBe(0.046366)
        ->and($result->isSignificantAt(90.0))->toBeTrue()
        ->and($result->isSignificantAt(95.0))->toBeTrue()
        ->and($result->isSignificantAt(99.0))->toBeFalse();
});

it('returns z = 0 and p = 1 for identical proportions', function (): void {
    $result = $this->test->compare(120, 1000, 120, 1000);

    expect($result->z)->toBe(0.0)
        ->and(round($result->pValue, 6))->toBe(1.0)
        ->and($result->isSignificantAt(90.0))->toBeFalse();
});

// The normal approximation is refused below MIN_TRIALS rather than dressed up.
it('refuses to compute below the minimum sample size', function (): void {
    $result = $this->test->compare(5, 10, 8, 10);

    expect($result->computable)->toBeFalse()
        ->and($result->pValue)->toBe(1.0)
        ->and($result->confidencePercent())->toBe(0.0)
        ->and($result->isSignificantAt(90.0))->toBeFalse()
        // The raw rates are still reported honestly.
        ->and($result->rateA)->toBe(0.5)
        ->and(round($result->rateB, 1))->toBe(0.8);
});

it('refuses to compute when there is zero observed variance', function (): void {
    // Both arms 100 % — pooled variance is 0, so there is no evidence at all.
    $result = $this->test->compare(50, 50, 60, 60);

    expect($result->computable)->toBeFalse()
        ->and($result->pValue)->toBe(1.0);
});

it('treats an empty denominator as not computable', function (): void {
    expect($this->test->compare(0, 0, 10, 100)->computable)->toBeFalse()
        ->and($this->test->compare(10, 100, 0, 0)->computable)->toBeFalse();
});

it('is symmetric: swapping the arms only flips the sign of z', function (): void {
    $forward = $this->test->compare(100, 1000, 150, 1000);
    $reverse = $this->test->compare(150, 1000, 100, 1000);

    expect(round($forward->z + $reverse->z, 10))->toBe(0.0)
        ->and(round($forward->pValue, 10))->toBe(round($reverse->pValue, 10));
});
