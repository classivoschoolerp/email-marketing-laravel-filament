<?php

declare(strict_types=1);

namespace App\Services\Quota;

use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\SendingDomain;
use App\Models\Subscriber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Per-customer usage vs quota numbers (spec §2.1 F-2.1-002 + §2.15.5,
 * WP-18). Reads limits through QuotaService (per-customer override > group
 * matrix > config default) and usage from the same sources WP-20's
 * QuotaNotifier uses: delivered campaign_delivery_logs rows within the
 * hour/day/calendar-month window (UTC, docs/ARCHITECTURE.md). Storage is computed from
 * the customer's real directories on the public + local disks
 * (customers/{uid}/**, the layout every module writes to).
 */
class QuotaUsageService
{
    public function __construct(private readonly QuotaService $quotas) {}

    /* ---------------------------------------------------------- sending */

    /**
     * Hourly / daily / monthly sending usage with reset times (F-2.15.5-002).
     *
     * @return array<string, array{used: int, limit: ?int, percent: ?int, resets_at: Carbon}>
     */
    public function sendingUsage(Customer $customer): array
    {
        $windows = [
            'hourly' => ['sends_per_hour', now()->startOfHour(), now()->startOfHour()->addHour()],
            'daily' => ['sends_per_day', now()->startOfDay(), now()->startOfDay()->addDay()],
            'monthly' => ['sends_per_month', now()->startOfMonth(), now()->startOfMonth()->addMonth()],
        ];

        $usage = [];

        foreach ($windows as $window => [$key, $since, $resetsAt]) {
            $limit = $this->limit($customer, $key);
            $used = $this->sentSince($customer, $since);

            $usage[$window] = [
                'used' => $used,
                'limit' => $limit,
                'percent' => $limit === null ? null : (int) min(100, floor($used * 100 / max(1, $limit))),
                'resets_at' => $resetsAt,
            ];
        }

        return $usage;
    }

    /** Delivered rows since $since across the customer's campaigns. */
    public function sentSince(Customer $customer, Carbon $since): int
    {
        return CampaignDeliveryLog::query()
            ->whereIn('campaign_id', $this->campaignIds($customer))
            ->where('status', CampaignDeliveryLog::STATUS_SENT)
            ->where('created_at', '>=', $since)
            ->count();
    }

    /* ----------------------------------------------------- object counts */

    /**
     * Object counts vs their limits (F-2.15.5-003/004): subscribers, lists,
     * campaigns, delivery servers, sending domains.
     *
     * @return array<string, array{used: int, limit: ?int}>
     */
    public function objectUsage(Customer $customer): array
    {
        $counts = [
            'subscribers' => ['max_subscribers', Subscriber::withoutTenancy()->where('customer_id', $customer->id)->count()],
            'lists' => ['max_lists', EmailList::withoutTenancy()->where('customer_id', $customer->id)->count()],
            'campaigns' => ['max_campaigns', Campaign::withoutTenancy()->where('customer_id', $customer->id)->count()],
            'delivery_servers' => ['max_delivery_servers', DeliveryServer::withoutTenancy()->where('customer_id', $customer->id)->count()],
            'sending_domains' => ['max_sending_domains', SendingDomain::withoutTenancy()->where('customer_id', $customer->id)->count()],
        ];

        $usage = [];

        foreach ($counts as $name => [$key, $used]) {
            $usage[$name] = ['used' => $used, 'limit' => $this->limit($customer, $key)];
        }

        return $usage;
    }

    /* ----------------------------------------------------------- storage */

    /**
     * Bytes stored under the customer's directories (F-2.1-002 /
     * F-2.15.5-005): campaign + template assets on the public disk, private
     * imports/exports/verifications on the local disk.
     */
    public function storageBytes(Customer $customer): int
    {
        $bytes = 0;

        foreach (['public', 'local'] as $disk) {
            $storage = Storage::disk($disk);

            foreach ($storage->allFiles('customers/'.$customer->uid) as $file) {
                $bytes += (int) $storage->size($file);
            }
        }

        return $bytes;
    }

    /* ------------------------------------------------ historical series */

    /**
     * Delivered emails per calendar month, oldest first, empty months
     * included (F-2.15.5-006). Reads the campaign_stats_daily rollup;
     * substr() exists on SQLite and MySQL alike (D-03).
     *
     * @return Collection<string, int> 'Y-m' => sent
     */
    public function monthlySendSeries(Customer $customer, int $months = 12): Collection
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $rows = DB::table('campaign_stats_daily')
            ->whereIn('campaign_id', $this->campaignIds($customer))
            ->where('day', '>=', $start->format('Y-m-d'))
            ->selectRaw("substr(day, 1, 7) as month, sum(sent) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $buckets = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i)->format('Y-m');
            $buckets[$month] = (int) ($rows[$month] ?? 0);
        }

        return collect($buckets);
    }

    /* ----------------------------------------------------------- helpers */

    /** Any tracked usage at or above the given percent? (F-2.15.5-007.) */
    public function nearAnyLimit(Customer $customer, int $percent = 80): bool
    {
        foreach ($this->sendingUsage($customer) as $window) {
            if ($window['percent'] !== null && $window['percent'] >= $percent) {
                return true;
            }
        }

        foreach ($this->objectUsage($customer) as $object) {
            if ($object['limit'] !== null && $object['limit'] > 0
                && $object['used'] * 100 / $object['limit'] >= $percent) {
                return true;
            }
        }

        return false;
    }

    public function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }

    /** Effective limit; null = unlimited (0 also means unlimited per D-23). */
    public function limit(Customer $customer, string $key): ?int
    {
        $limit = $this->quotas->limitFor($customer, $key);

        return $limit === null || $limit <= 0 ? null : $limit;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Campaign> id subquery incl. trashed */
    private function campaignIds(Customer $customer)
    {
        return Campaign::withoutTenancy()
            ->withTrashed()
            ->where('customer_id', $customer->id)
            ->select('id');
    }
}
