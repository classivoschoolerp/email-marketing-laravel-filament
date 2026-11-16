<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\AutomationNode;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;
use Illuminate\Support\Carbon;

/**
 * F-2.4.2-034 — delay: fixed duration (min/hr/day/week), until a specific
 * date, until day-of-week + time-of-day, or until the contact does X
 * (parks on an event like a wait node; the optional timeout also just
 * continues on the single `next` branch).
 *
 * Deterministic + re-entrant: the due moment derives from the run's
 * node_entered_at, so re-executions compute the same alarm.
 */
class DelayExecutor implements NodeExecutor
{
    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        $mode = (string) $node->config('mode', 'duration');
        $entered = Carbon::parse($ctx->run->node_entered_at ?? $ctx->now());

        if ($ctx->dryRun) {
            return NodeResult::continue(detail: ['skipped_delay' => $mode]);
        }

        if ($mode === 'until_event') {
            if ($ctx->run->resume_branch === 'event') {
                return NodeResult::continue(detail: ['woke' => 'event']);
            }

            $timeoutAt = $this->timeoutAt($node, $entered);

            if ($timeoutAt !== null && $ctx->now()->greaterThanOrEqualTo($timeoutAt)) {
                return NodeResult::continue(detail: ['woke' => 'timeout']);
            }

            return NodeResult::wait((string) $node->config('event', ''), $timeoutAt, ['mode' => 'until_event']);
        }

        $due = $this->dueAt($node, $entered, $ctx);

        if ($ctx->now()->greaterThanOrEqualTo($due)) {
            return NodeResult::continue(detail: ['woke' => 'delay']);
        }

        return NodeResult::sleep($due, detail: ['until' => $due->toIso8601String()]);
    }

    private function dueAt(AutomationNode $node, Carbon $entered, ExecutionContext $ctx): Carbon
    {
        $mode = (string) $node->config('mode', 'duration');

        if ($mode === 'until_date') {
            return Carbon::parse((string) $node->config('date', $entered->toDateTimeString()), $ctx->timezone())->utc();
        }

        if ($mode === 'until_time') {
            return $this->nextWeekdayTime(
                $entered,
                $node->config('weekday') !== null && $node->config('weekday') !== '' ? (int) $node->config('weekday') : null,
                (string) $node->config('time', '09:00'),
                $ctx->timezone(),
            );
        }

        return self::addDuration($entered, (int) $node->config('value', 1), (string) $node->config('unit', 'hours'));
    }

    private function timeoutAt(AutomationNode $node, Carbon $entered): ?Carbon
    {
        $value = $node->config('timeout_value');

        if ($value === null || $value === '') {
            return null;
        }

        return self::addDuration($entered, (int) $value, (string) $node->config('timeout_unit', 'days'));
    }

    public static function addDuration(Carbon $from, int $value, string $unit): Carbon
    {
        $value = max(0, $value);

        return match ($unit) {
            'minutes' => $from->copy()->addMinutes($value),
            'days' => $from->copy()->addDays($value),
            'weeks' => $from->copy()->addWeeks($value),
            default => $from->copy()->addHours($value),
        };
    }

    /** Next occurrence of (weekday?, HH:MM) at/after $from, in $timezone. */
    private function nextWeekdayTime(Carbon $from, ?int $weekday, string $time, string $timezone): Carbon
    {
        $local = $from->copy()->setTimezone($timezone);
        $candidate = $local->copy()->setTimeFromTimeString($time !== '' ? $time : '09:00');

        if ($weekday === null) {
            if ($candidate->lessThan($local)) {
                $candidate->addDay();
            }

            return $candidate->utc();
        }

        $weekday = max(0, min(6, $weekday));

        while ((int) $candidate->dayOfWeek !== $weekday || $candidate->lessThan($local)) {
            $candidate->addDay();
        }

        return $candidate->utc();
    }
}
