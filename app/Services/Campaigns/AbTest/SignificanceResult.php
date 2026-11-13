<?php

declare(strict_types=1);

namespace App\Services\Campaigns\AbTest;

/**
 * Outcome of one two-proportion z-test (spec §2.3.6 F-2.3.6-010/011).
 *
 * `pValue` is the TWO-TAILED probability of observing a difference at least
 * this large if both variants had the same true rate. `confidence` is simply
 * 1 − p expressed as a percentage, which is the number the UI shows.
 */
final readonly class SignificanceResult
{
    public function __construct(
        public float $rateA,
        public float $rateB,
        public float $z,
        public float $pValue,
        /** False when the test could not be run (empty denominators, zero variance). */
        public bool $computable,
    ) {}

    /** 1 − p as a percentage, 0.0 when the test could not be run. */
    public function confidencePercent(): float
    {
        return $this->computable ? round((1.0 - $this->pValue) * 100, 4) : 0.0;
    }

    /** True when p ≤ 1 − level/100 (level 95 ⇒ alpha 0.05). */
    public function isSignificantAt(float $levelPercent): bool
    {
        if (! $this->computable) {
            return false;
        }

        return $this->pValue <= max(0.0, 1.0 - $levelPercent / 100);
    }
}
