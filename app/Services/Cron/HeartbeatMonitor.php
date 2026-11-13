<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Console\Commands\CronHeartbeat;
use App\Mail\CronHeartbeatStaleMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Cron heartbeat monitoring + admin alert (spec §0.6 F-0.6-018, WP-13).
 * `system:cron-heartbeat` stamps a cache timestamp every minute; this check
 * (invoked from the hourly stats:rollup body — see routes/console.php) mails
 * every admin user when the stamp is missing or older than the configured
 * staleness window, throttled by a cooldown so a dead heartbeat does not
 * flood inboxes. If the scheduler itself is fully dead nothing in-process
 * can fire — pair with external uptime monitoring in production (documented
 * limitation; the in-app alert catches heartbeat-command failures and
 * partially broken schedules).
 */
class HeartbeatMonitor
{
    public const COOLDOWN_CACHE_KEY = 'cron.heartbeat_alerted_at';

    /** @return bool true when an alert was sent */
    public function checkAndAlert(): bool
    {
        if (! $this->isStale()) {
            return false;
        }

        $cooldownMinutes = max(1, (int) config('emp-sending.heartbeat.alert_cooldown_minutes', 240));

        if (Cache::get(self::COOLDOWN_CACHE_KEY) !== null) {
            return false; // alerted recently
        }

        $recipients = User::query()->pluck('email')->filter()->values();

        if ($recipients->isEmpty()) {
            return false;
        }

        foreach ($recipients as $email) {
            Mail::to((string) $email)->queue(new CronHeartbeatStaleMail($this->lastSeenAt()?->toIso8601String()));
        }

        Cache::put(self::COOLDOWN_CACHE_KEY, Carbon::now()->toIso8601String(), $cooldownMinutes * 60);

        return true;
    }

    public function isStale(): bool
    {
        $staleMinutes = max(1, (int) config('emp-sending.heartbeat.stale_minutes', 15));
        $last = $this->lastSeenAt();

        return $last === null || $last->lessThan(Carbon::now()->subMinutes($staleMinutes));
    }

    public function lastSeenAt(): ?Carbon
    {
        $stamp = Cache::get(CronHeartbeat::CACHE_KEY);

        if (! is_string($stamp) || $stamp === '') {
            return null;
        }

        try {
            return Carbon::parse($stamp);
        } catch (Throwable) {
            return null;
        }
    }
}
