<?php

declare(strict_types=1);

namespace App\Services\Warmup;

use App\Models\DeliveryServer;
use App\Models\DeliveryServerUsage;
use App\Models\WarmupDailyUsage;
use App\Models\WarmupProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hard per-day warmup enforcement (spec §2.9.3 F-2.9.3-009/010/012, WP-33).
 *
 * The ceiling for a server on a given date is the allowance of its warmup
 * day, snapshotted onto a warmup_daily_usage row the first time the day is
 * touched. Consumption is `sent + reserved`:
 *
 *  - `sent` / `failed` MIRROR delivery_server_usage — the send path's single
 *    source of truth (ServerUsage). Nothing in the send pipeline had to learn
 *    about warmup, so there is no second bookkeeping path to drift.
 *  - `reserved` is capacity ServerPicker handed out for an in-flight send.
 *    It is what makes the cap hard under concurrency: two workers cannot both
 *    win the last slot, because the reservation is a conditional UPDATE.
 *    A reservation settles as soon as its send lands (sent or failed) and a
 *    stale one — a pick that never reached the driver, e.g. a spam-blocked
 *    message — expires after emp-warmup.reservation_ttl seconds.
 *
 * Over-quota traffic is DEFERRED, not failed: ServerPicker turns an all-
 * capped candidate set into PickResult::pause(secondsUntilNextDay()), which
 * SendToSubscriberJob already handles by dropping its claim row and releasing
 * the idempotency key (D-61) so the next send wave retries the recipient.
 */
class WarmupQuota
{
    public function __construct(private readonly WarmupScheduleBuilder $schedules) {}

    /**
     * The enforcing warmup state for a server, or null when nothing caps it
     * (no plan assigned, plan draft/completed, or no progress row).
     */
    public function progressFor(DeliveryServer $server): ?WarmupProgress
    {
        if ($server->warmup_plan_id === null) {
            return null;
        }

        /** @var WarmupProgress|null $progress */
        $progress = WarmupProgress::query()
            ->with('plan')
            ->where('warmup_plan_id', $server->warmup_plan_id)
            ->where('delivery_server_id', $server->id)
            ->first();

        if ($progress === null || $progress->plan === null) {
            return null;
        }

        return $progress->enforces() ? $progress : null;
    }

    /** Would sending $amount more break this server's warmup allowance? */
    public function wouldExceed(DeliveryServer $server, int $amount = 1): bool
    {
        $progress = $this->progressFor($server);

        if ($progress === null) {
            return false;
        }

        $row = $this->today($progress, $server);

        return ((int) $row->sent + (int) $row->reserved + max(1, $amount)) > (int) $row->allowance;
    }

    /**
     * Atomically claim $amount of today's allowance. Returns false when the
     * day is full — the caller must then skip this server.
     */
    public function reserve(DeliveryServer $server, int $amount = 1): bool
    {
        $progress = $this->progressFor($server);

        if ($progress === null) {
            return true; // nothing to reserve against
        }

        $row = $this->today($progress, $server);
        $amount = max(1, $amount);

        $affected = DB::table('warmup_daily_usage')
            ->where('id', $row->id)
            ->whereRaw('(sent + reserved + ?) <= allowance', [$amount])
            ->update([
                'reserved' => DB::raw('reserved + '.$amount),
                'reserved_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        return $affected > 0;
    }

    /**
     * Today's usage row for a server's warmup progress, refreshed from the
     * delivery-server rollup. Creates the row (snapshotting the allowance and
     * the pre-warmup baseline) on first touch.
     */
    public function today(WarmupProgress $progress, ?DeliveryServer $server = null, ?Carbon $date = null): WarmupDailyUsage
    {
        $date = ($date ?? Carbon::today())->startOfDay();
        $server ??= $progress->server;

        /** @var WarmupDailyUsage|null $row */
        $row = WarmupDailyUsage::query()
            ->where('warmup_progress_id', $progress->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        [$usageSent, $usageFailed] = $this->rollup((int) $progress->delivery_server_id, $date);

        if ($row === null) {
            $row = WarmupDailyUsage::query()->create([
                'warmup_progress_id' => $progress->id,
                'date' => $date->toDateString(),
                'day' => (int) $progress->current_day,
                'allowance' => $this->allowanceFor($progress),
                'baseline_sent' => $usageSent,
                'baseline_failed' => $usageFailed,
            ]);
        }

        return $this->sync($row, $usageSent, $usageFailed);
    }

    /**
     * Mirror the rollup onto the day row and drain settled/stale
     * reservations. Idempotent — safe to call on every pick.
     */
    public function sync(WarmupDailyUsage $row, ?int $usageSent = null, ?int $usageFailed = null): WarmupDailyUsage
    {
        if ($usageSent === null || $usageFailed === null) {
            $progress = $row->progress;
            [$usageSent, $usageFailed] = $this->rollup(
                (int) ($progress?->delivery_server_id ?? 0),
                Carbon::parse($row->date),
            );
        }

        $sent = max(0, $usageSent - (int) $row->baseline_sent);
        $failed = max(0, $usageFailed - (int) $row->baseline_failed);
        $outcomes = $sent + $failed;

        $reserved = (int) $row->reserved;
        $settled = (int) $row->settled;

        // Every landed outcome releases one reserved slot.
        $reserved = max(0, $reserved - max(0, $outcomes - $settled));

        // A reservation whose send never reached the driver must not hold a
        // slot forever (spam block, worker crash between pick and send).
        $ttl = (int) config('emp-warmup.reservation_ttl', 900);

        if ($reserved > 0 && $ttl > 0 && $row->reserved_at !== null
            && $row->reserved_at->lt(Carbon::now()->subSeconds($ttl))) {
            $reserved = 0;
        }

        $row->forceFill([
            'sent' => $sent,
            'failed' => $failed,
            'reserved' => $reserved,
            'settled' => $outcomes,
        ])->save();

        return $row;
    }

    /** Allowance the plan prescribes for the progress row's current day. */
    public function allowanceFor(WarmupProgress $progress): int
    {
        $plan = $progress->plan;

        if ($plan === null) {
            return 0;
        }

        $stored = $plan->allowanceForDay((int) $progress->current_day);

        if ($stored > 0) {
            return $stored;
        }

        // Schedule not generated yet — fall back to the curve so enforcement
        // is never accidentally unlimited.
        return $this->schedules->allowanceFor(
            (int) $progress->current_day,
            (int) $plan->start_volume,
            $plan->strategy,
            $plan->increment_type,
            (int) $plan->increment_value,
            (int) $plan->max_daily_volume,
        );
    }

    /**
     * Progress-view figures (F-2.9.3-004).
     *
     * @return array{day: int, days: int, allowance: int, sent: int, failed: int, reserved: int, remaining: int}
     */
    public function snapshot(WarmupProgress $progress): array
    {
        $row = $this->today($progress);

        return [
            'day' => (int) $progress->current_day,
            'days' => (int) ($progress->plan?->days ?? 0),
            'allowance' => (int) $row->allowance,
            'sent' => (int) $row->sent,
            'failed' => (int) $row->failed,
            'reserved' => (int) $row->reserved,
            'remaining' => $row->remaining(),
        ];
    }

    /** Seconds until the warmup day rolls over — the deferral horizon. */
    public function secondsUntilNextDay(): int
    {
        return max(1, (int) Carbon::now()->diffInSeconds(Carbon::tomorrow(), absolute: true));
    }

    /**
     * @return array{0: int, 1: int} [sent, failed] from delivery_server_usage
     */
    private function rollup(int $serverId, Carbon $date): array
    {
        /** @var DeliveryServerUsage|null $usage */
        $usage = DeliveryServerUsage::query()
            ->where('delivery_server_id', $serverId)
            ->whereDate('date', $date->toDateString())
            ->first();

        return [(int) ($usage->sent ?? 0), (int) ($usage->failed ?? 0)];
    }
}
