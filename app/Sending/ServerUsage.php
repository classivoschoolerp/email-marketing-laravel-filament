<?php

declare(strict_types=1);

namespace App\Sending;

use App\Models\DeliveryServer;
use App\Models\DeliveryServerUsage;
use App\Support\RateLimit\HourlyWindowLimiter;
use Illuminate\Support\Carbon;

/**
 * Per-server send counters (spec §2.9.2 F-2.9.2-029, WP-14):
 *  - hourly: HourlyWindowLimiter fixed cache window (Redis in prod, D-04)
 *  - daily/monthly: delivery_server_usage daily rollup rows (no campaign FK)
 *
 * ServerPicker reads headroom from here; the send path records through here.
 */
class ServerUsage
{
    public function __construct(private readonly HourlyWindowLimiter $hourly) {}

    /** Record a successful send on every counter + last_used_at. */
    public function recordSend(DeliveryServer $server, int $amount = 1): void
    {
        $this->hourly->hit($server->hourlyQuotaKey(), $amount);

        $row = DeliveryServerUsage::query()->firstOrCreate([
            'delivery_server_id' => $server->id,
            'date' => Carbon::today(),
        ]);
        $row->increment('sent', $amount);

        $server->forceFill(['last_used_at' => Carbon::now()])->saveQuietly();
    }

    /** Record a failed attempt (rollup only — failures don't consume quota). */
    public function recordFailure(DeliveryServer $server, int $amount = 1): void
    {
        $row = DeliveryServerUsage::query()->firstOrCreate([
            'delivery_server_id' => $server->id,
            'date' => Carbon::today(),
        ]);
        $row->increment('failed', $amount);
    }

    public function usedThisHour(DeliveryServer $server): int
    {
        return $this->hourly->used($server->hourlyQuotaKey());
    }

    public function usedToday(DeliveryServer $server): int
    {
        return (int) DeliveryServerUsage::query()
            ->where('delivery_server_id', $server->id)
            ->whereDate('date', Carbon::today())
            ->sum('sent');
    }

    public function usedThisMonth(DeliveryServer $server): int
    {
        return (int) DeliveryServerUsage::query()
            ->where('delivery_server_id', $server->id)
            ->whereBetween('date', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->sum('sent');
    }

    /** Would sending $amount more break any quota window? 0 = unlimited (D-23). */
    public function wouldExceedAnyQuota(DeliveryServer $server, int $amount = 1): bool
    {
        return $this->wouldExceedHourly($server, $amount)
            || $this->wouldExceedDaily($server, $amount)
            || $this->wouldExceedMonthly($server, $amount);
    }

    public function wouldExceedHourly(DeliveryServer $server, int $amount = 1): bool
    {
        return $this->hourly->wouldExceed($server->hourlyQuotaKey(), $server->hourly_quota, $amount);
    }

    public function wouldExceedDaily(DeliveryServer $server, int $amount = 1): bool
    {
        return $server->daily_quota > 0
            && ($this->usedToday($server) + $amount) > $server->daily_quota;
    }

    public function wouldExceedMonthly(DeliveryServer $server, int $amount = 1): bool
    {
        return $server->monthly_quota > 0
            && ($this->usedThisMonth($server) + $amount) > $server->monthly_quota;
    }

    public function secondsUntilHourlyReset(): int
    {
        return $this->hourly->secondsUntilReset();
    }
}
