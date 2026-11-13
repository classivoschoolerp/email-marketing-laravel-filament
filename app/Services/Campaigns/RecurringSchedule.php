<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Enums\RecurringInterval;
use App\Models\Campaign;
use Cron\CronExpression;
use Illuminate\Support\Carbon;

/**
 * Recurring-campaign schedule maths (spec §2.3.2 F-2.3.2-019..022).
 *
 * Every interval — including the simple hourly/daily/weekly/monthly/yearly
 * presets with their days-of-week / day-of-month refinements — is compiled
 * to a cron expression and evaluated with Cron\CronExpression (dragonmantank,
 * ships with Laravel), so the preview and the WP-13 runner share one
 * deterministic engine. All maths run in the campaign's timezone; stored
 * next_run_at is UTC (docs/ARCHITECTURE.md timestamp rule).
 */
class RecurringSchedule
{
    public function isValidCron(?string $expression): bool
    {
        return $expression !== null && CronExpression::isValidExpression($expression);
    }

    /**
     * The cron expression driving this campaign, or null when the recurring
     * configuration is incomplete/invalid.
     */
    public function cronFor(Campaign $campaign): ?string
    {
        $interval = $campaign->rec_interval;

        if (! $interval instanceof RecurringInterval) {
            return null;
        }

        if ($interval === RecurringInterval::CustomCron) {
            return $this->isValidCron($campaign->rec_cron) ? $campaign->rec_cron : null;
        }

        $start = $campaign->rec_starts_at ?? $campaign->send_at ?? Carbon::now();
        $start = $start->copy()->setTimezone($campaign->timezone ?: 'UTC');

        $minute = $start->minute;
        $hour = $start->hour;

        $daysOfWeek = collect($campaign->rec_days_of_week ?? [])
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 0 && $day <= 6)
            ->unique()->sort()->implode(',');

        $dayOfMonth = $campaign->rec_day_of_month !== null
            ? (int) $campaign->rec_day_of_month
            : $start->day;

        return match ($interval) {
            RecurringInterval::Hourly => sprintf('%d * * * *', $minute),
            RecurringInterval::Daily => sprintf('%d %d * * %s', $minute, $hour, $daysOfWeek !== '' ? $daysOfWeek : '*'),
            RecurringInterval::Weekly => sprintf('%d %d * * %s', $minute, $hour, $daysOfWeek !== '' ? $daysOfWeek : (string) $start->dayOfWeek),
            RecurringInterval::Monthly => sprintf('%d %d %d * *', $minute, $hour, $dayOfMonth),
            RecurringInterval::Yearly => sprintf('%d %d %d %d *', $minute, $hour, $dayOfMonth, $start->month),
            RecurringInterval::CustomCron => null, // handled above
        };
    }

    /**
     * Next-run preview (F-2.3.2-022): the next $count occurrences in the
     * campaign's timezone, respecting start date, end date and remaining
     * max-occurrences. Empty when the configuration is invalid or exhausted.
     *
     * @return list<Carbon>
     */
    public function nextRunPreview(Campaign $campaign, int $count = 3, ?Carbon $from = null): array
    {
        $cron = $this->cronFor($campaign);

        if ($cron === null) {
            return [];
        }

        $timezone = $campaign->timezone ?: 'UTC';
        $expression = new CronExpression($cron);

        $cursor = ($from ?? Carbon::now())->copy()->setTimezone($timezone);

        $start = $campaign->rec_starts_at?->copy()->setTimezone($timezone);

        if ($start !== null && $start->greaterThan($cursor)) {
            // First run may be exactly at the start moment.
            $cursor = $start->copy()->subSecond();
        }

        $end = $campaign->rec_ends_at?->copy()->setTimezone($timezone);

        $remaining = $campaign->rec_max_occurrences !== null
            ? max(0, (int) $campaign->rec_max_occurrences - (int) $campaign->rec_occurrences)
            : null;

        $count = min($count, $remaining ?? $count);
        $runs = [];
        $native = $cursor->toDateTime();

        while (count($runs) < $count) {
            try {
                $native = $expression->getNextRunDate($native, 0, false, $timezone);
            } catch (\RuntimeException) {
                break; // no more matching dates
            }

            $run = Carbon::instance($native)->setTimezone($timezone);

            if ($end !== null && $run->greaterThan($end)) {
                break;
            }

            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * The single next run (UTC) to persist on campaigns.next_run_at, or
     * null when the schedule is invalid or exhausted.
     */
    public function nextRunAt(Campaign $campaign, ?Carbon $from = null): ?Carbon
    {
        $runs = $this->nextRunPreview($campaign, 1, $from);

        return isset($runs[0]) ? $runs[0]->copy()->utc() : null;
    }
}
