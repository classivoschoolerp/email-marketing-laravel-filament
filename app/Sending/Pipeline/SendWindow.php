<?php

declare(strict_types=1);

namespace App\Sending\Pipeline;

use App\Models\Campaign;
use Illuminate\Support\Carbon;

/**
 * Campaign send-window gate (spec §2.3.4 delivery options, WP-13).
 * `send_window_days` is a list of cron day-of-week numbers (0 = Sunday);
 * `send_window_start` / `send_window_end` are raw TIME strings. All checks
 * run in the campaign's timezone. Empty settings mean "always open"; an
 * end before the start describes an overnight window (e.g. 22:00 → 06:00).
 */
class SendWindow
{
    public function isOpen(Campaign $campaign, ?Carbon $now = null): bool
    {
        $moment = ($now ?? Carbon::now())->copy()->setTimezone($campaign->timezone ?: 'UTC');

        $days = array_map('intval', (array) ($campaign->send_window_days ?? []));

        if ($days !== [] && ! in_array($moment->dayOfWeek, $days, true)) {
            return false;
        }

        $start = $this->minutes($campaign->send_window_start);
        $end = $this->minutes($campaign->send_window_end);

        if ($start === null && $end === null) {
            return true;
        }

        $current = $moment->hour * 60 + $moment->minute;
        $start ??= 0;
        $end ??= 24 * 60 - 1;

        if ($start <= $end) {
            return $current >= $start && $current <= $end;
        }

        // Overnight window (start later than end).
        return $current >= $start || $current <= $end;
    }

    /** "HH:MM[:SS]" → minutes since midnight, null when unset/blank. */
    private function minutes(?string $time): ?int
    {
        if ($time === null || trim($time) === '') {
            return null;
        }

        $parts = explode(':', trim($time));

        return ((int) $parts[0]) * 60 + (int) ($parts[1] ?? 0);
    }
}
