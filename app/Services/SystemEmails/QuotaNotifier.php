<?php

declare(strict_types=1);

namespace App\Services\SystemEmails;

use App\Enums\NotificationEvent;
use App\Mail\QuotaReachedMail;
use App\Mail\QuotaWarningMail;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\Customer;
use App\Services\Notifications\CustomerNotifier;
use App\Services\Quota\QuotaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Sending-quota threshold notifications (spec §3.15 F-3.15-008, WP-20):
 * compares a customer's calendar-month delivered count against the
 * `sends_per_month` limit from the §0.3/§3.4 quota matrix (QuotaService —
 * per-customer override > group > config default) and queues the warning /
 * reached mail once per customer, month and level (cache-deduped, so a cron
 * can call this repeatedly without flooding inboxes).
 *
 * Wiring: exposed as a plain invokable service — the intended production
 * caller is the hourly stats:rollup body (routes/console.php is frozen this
 * wave; the one-line `app(QuotaNotifier::class)->checkAll()` hook is a
 * documented coordinator follow-up). Admin/test code calls check() directly.
 */
class QuotaNotifier
{
    public const LEVEL_WARNING = 'warning';

    public const LEVEL_REACHED = 'reached';

    public function __construct(private readonly QuotaService $quotas) {}

    /**
     * Check one customer; queues at most one mail. Returns the level that
     * was (newly) notified, or null when under threshold / unlimited /
     * already notified this month.
     */
    public function check(Customer $customer): ?string
    {
        $limit = $this->quotas->limitFor($customer, 'sends_per_month');

        // null and 0 both mean unlimited (QuotaService::UNLIMITED + D-23).
        if ($limit === null || $limit <= 0) {
            return null;
        }

        $used = $this->monthlyUsage($customer);
        $percent = (int) floor($used / $limit * 100);

        if ($used >= $limit) {
            return $this->notifyOnce($customer, self::LEVEL_REACHED, new QuotaReachedMail($used, $limit), $used, $limit, $percent);
        }

        if ($percent >= $this->warningPercent()) {
            return $this->notifyOnce(
                $customer,
                self::LEVEL_WARNING,
                new QuotaWarningMail($used, $limit, $percent),
                $used,
                $limit,
                $percent,
            );
        }

        return null;
    }

    /** Sweep every active customer (the documented cron entry point). */
    public function checkAll(): void
    {
        Customer::query()->whereNotNull('email')->eachById(function (Customer $customer): void {
            $this->check($customer);
        });
    }

    /** Delivered count for the current calendar month (UTC, docs/ARCHITECTURE.md). */
    public function monthlyUsage(Customer $customer): int
    {
        return CampaignDeliveryLog::query()
            ->whereIn(
                'campaign_id',
                Campaign::withoutTenancy()->where('customer_id', $customer->id)->select('id'),
            )
            ->where('status', CampaignDeliveryLog::STATUS_SENT)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /** Warning threshold percent (default 80, configurable). */
    public function warningPercent(): int
    {
        return max(1, min(100, (int) config('emp.quotas.warning_percent', 80)));
    }

    /**
     * WP-39 §2.15.3: the once-per-month dedupe still governs WHETHER the
     * account is told; the customer's per-channel preferences govern HOW.
     * A customer who switched both channels off consumes the marker anyway
     * — re-evaluating the same threshold on the next cron tick would be
     * pointless work, and switching a channel back on should not replay a
     * threshold crossed weeks ago.
     */
    private function notifyOnce(
        Customer $customer,
        string $level,
        QuotaWarningMail|QuotaReachedMail $mail,
        int $used = 0,
        int $limit = 0,
        int $percent = 0,
    ): ?string {
        $cacheKey = sprintf('quota-notice.%d.%s.%s', $customer->id, now()->format('Y-m'), $level);

        if (Cache::get($cacheKey) !== null) {
            return null; // already notified this month
        }

        $notifier = app(CustomerNotifier::class);

        $notifier->inApp(
            $customer,
            NotificationEvent::QuotaNearingLimit,
            $level === self::LEVEL_REACHED
                ? __('notifications.in_app.quota_reached.title')
                : __('notifications.in_app.quota_warning.title'),
            $level === self::LEVEL_REACHED
                ? __('notifications.in_app.quota_reached.body', ['limit' => $limit])
                : __('notifications.in_app.quota_warning.body', ['used' => $used, 'limit' => $limit]),
            status: $level === self::LEVEL_REACHED ? 'danger' : 'warning',
            icon: 'heroicon-o-chart-bar',
        );

        if ($notifier->allowsEmail($customer, NotificationEvent::QuotaNearingLimit)) {
            Mail::to((string) $customer->email)->queue($mail);
        }

        // Keep the marker until well past month end; the key itself is
        // month-scoped so a new month notifies again.
        Cache::put($cacheKey, now()->toIso8601String(), now()->addDays(45));

        return $level;
    }
}
