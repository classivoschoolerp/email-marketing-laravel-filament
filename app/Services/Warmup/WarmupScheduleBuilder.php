<?php

declare(strict_types=1);

namespace App\Services\Warmup;

use App\Enums\WarmupIncrementType;
use App\Enums\WarmupStrategy;
use App\Models\WarmupPlan;
use App\Models\WarmupPlanSchedule;

/**
 * Turns a plan's ramp definition into the day-by-day schedule table
 * (spec §2.9.3 F-2.9.3-002/007/008).
 *
 * The curve — day 1 is always the start volume:
 *
 *   Linear       allowance(d) = start + step · (d − 1)
 *   Exponential  allowance(d) = round(start · factor^(d − 1))
 *
 * with the increment interpreted per {@see WarmupIncrementType}:
 *
 *   Fixed        step = increment                factor = (start + increment) / start
 *   Percent      step = round(start · pct/100)   factor = 1 + pct/100
 *
 * Both increment types therefore agree on day 2 and diverge only in how the
 * step behaves afterwards, which is exactly the difference between a linear
 * and an exponential ramp. Every value is floored at 1 and clamped to
 * max_daily_volume when that is non-zero (0 = uncapped, D-23).
 *
 * Nothing here is manual: the daily quota is ALWAYS derived from this curve
 * (F-2.9.3-008); operators may override individual DAYS in the generated
 * table, and those rows are marked `edited` and survive regeneration.
 */
class WarmupScheduleBuilder
{
    /** Hard ceiling on generated days — keeps a fat-fingered plan sane. */
    public const MAX_DAYS = 365;

    /**
     * Allowance for one day of a plan definition, without touching the DB.
     */
    public function allowanceFor(
        int $day,
        int $startVolume,
        WarmupStrategy $strategy,
        WarmupIncrementType $incrementType,
        int $incrementValue,
        int $maxDailyVolume = 0,
    ): int {
        $day = max(1, $day);
        $start = max(1, $startVolume);
        $increment = max(0, $incrementValue);

        $value = $strategy === WarmupStrategy::Exponential
            ? $start * $this->factor($start, $incrementType, $increment) ** ($day - 1)
            : $start + $this->step($start, $incrementType, $increment) * ($day - 1);

        $allowance = (int) round($value);
        $allowance = max(1, $allowance);

        if ($maxDailyVolume > 0) {
            $allowance = min($allowance, $maxDailyVolume);
        }

        return $allowance;
    }

    /**
     * The full curve for a plan, day => allowance.
     *
     * @return array<int, int>
     */
    public function curveFor(WarmupPlan $plan): array
    {
        $days = max(1, min((int) $plan->days, self::MAX_DAYS));
        $curve = [];

        for ($day = 1; $day <= $days; $day++) {
            $curve[$day] = $this->allowanceFor(
                $day,
                (int) $plan->start_volume,
                $plan->strategy,
                $plan->increment_type,
                (int) $plan->increment_value,
                (int) $plan->max_daily_volume,
            );
        }

        return $curve;
    }

    /**
     * (Re)build the plan's schedule rows.
     *
     * @param  bool  $force  true also rewrites hand-edited days (F-2.9.3-002)
     * @return int rows written
     */
    public function generate(WarmupPlan $plan, bool $force = false): int
    {
        $curve = $this->curveFor($plan);

        /** @var array<int, WarmupPlanSchedule> $existing */
        $existing = $plan->schedules()->get()->keyBy('day')->all();
        $written = 0;

        foreach ($curve as $day => $allowance) {
            $row = $existing[$day] ?? null;

            if ($row === null) {
                WarmupPlanSchedule::query()->create([
                    'warmup_plan_id' => $plan->id,
                    'day' => $day,
                    'allowance' => $allowance,
                    'edited' => false,
                ]);
                $written++;

                continue;
            }

            if ($row->edited && ! $force) {
                continue; // manual tuning survives regeneration
            }

            if ((int) $row->allowance !== $allowance || $row->edited) {
                $row->forceFill(['allowance' => $allowance, 'edited' => false])->save();
                $written++;
            }
        }

        // Shortening a plan drops the tail.
        $plan->schedules()->where('day', '>', count($curve))->delete();

        return $written;
    }

    /** Hand-edit one day; the row is flagged so regeneration preserves it. */
    public function setDay(WarmupPlan $plan, int $day, int $allowance): WarmupPlanSchedule
    {
        /** @var WarmupPlanSchedule $row */
        $row = $plan->schedules()->firstOrNew(['day' => max(1, $day)]);

        $row->forceFill([
            'warmup_plan_id' => $plan->id,
            'day' => max(1, $day),
            'allowance' => max(0, $allowance),
            'edited' => true,
        ])->save();

        return $row;
    }

    /** Constant absolute daily step for a linear ramp. */
    private function step(int $start, WarmupIncrementType $type, int $increment): int
    {
        return $type === WarmupIncrementType::Percent
            ? (int) round($start * $increment / 100)
            : $increment;
    }

    /** Compounding multiplier for an exponential ramp (never below 1.0). */
    private function factor(int $start, WarmupIncrementType $type, int $increment): float
    {
        $factor = $type === WarmupIncrementType::Percent
            ? 1 + ($increment / 100)
            : ($start + $increment) / $start;

        return max(1.0, $factor);
    }
}
