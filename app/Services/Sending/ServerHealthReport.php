<?php

declare(strict_types=1);

namespace App\Services\Sending;

use App\Models\CampaignDeliveryLog;
use App\Models\DeliveryServer;
use App\Models\DeliveryServerUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Server health / error-rate dashboard (spec §3.13.1 F-3.13.1-007, WP-33).
 *
 * Volume and failures come from the WP-14 delivery_server_usage rollups (the
 * counters the send path already maintains); the last driver error text comes
 * from campaign_delivery_logs, which is the only place a real SMTP/API
 * response is kept. Health is the error rate against the thresholds in
 * config emp-deliverability.server_health.
 */
class ServerHealthReport
{
    /**
     * One row per delivery server, worst health first.
     *
     * @return list<array{
     *     id: int, name: string, type: string, status: string, sent: int, failed: int,
     *     error_rate: float, health: string, last_used_at: Carbon|null, last_error: string|null
     * }>
     */
    public function rows(int $days = 7): array
    {
        $since = Carbon::today()->subDays(max(0, $days - 1));

        $usage = DeliveryServerUsage::query()
            ->where('date', '>=', $since->toDateString())
            ->groupBy('delivery_server_id')
            ->select('delivery_server_id', DB::raw('SUM(sent) as sent'), DB::raw('SUM(failed) as failed'))
            ->get()
            ->keyBy('delivery_server_id');

        $lastErrors = $this->lastErrors($since);

        $rows = [];

        foreach (DeliveryServer::withoutTenancy()->orderBy('id')->get() as $server) {
            $sent = (int) ($usage[$server->id]->sent ?? 0);
            $failed = (int) ($usage[$server->id]->failed ?? 0);
            $rate = $this->errorRate($sent, $failed);

            $rows[] = [
                'id' => (int) $server->id,
                'name' => (string) $server->name,
                'type' => (string) $server->type,
                'status' => $server->status->value,
                'sent' => $sent,
                'failed' => $failed,
                'error_rate' => $rate,
                'health' => $this->health($rate, $sent + $failed),
                'last_used_at' => $server->last_used_at,
                'last_error' => $lastErrors[$server->id] ?? null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['error_rate'] <=> $a['error_rate']);

        return $rows;
    }

    /**
     * Fleet totals for the dashboard header.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{servers: int, sent: int, failed: int, error_rate: float, unhealthy: int}
     */
    public function totals(array $rows): array
    {
        $sent = array_sum(array_column($rows, 'sent'));
        $failed = array_sum(array_column($rows, 'failed'));

        return [
            'servers' => count($rows),
            'sent' => $sent,
            'failed' => $failed,
            'error_rate' => $this->errorRate($sent, $failed),
            'unhealthy' => count(array_filter(
                $rows,
                static fn (array $row): bool => $row['health'] !== 'ok',
            )),
        ];
    }

    public function errorRate(int $sent, int $failed): float
    {
        $attempts = $sent + $failed;

        return $attempts > 0 ? $failed / $attempts : 0.0;
    }

    /**
     * "ok" | "warning" | "critical". Servers below the minimum-attempts floor
     * always read healthy — one failure out of three sends is noise, not a
     * 33% error rate.
     */
    public function health(float $rate, int $attempts): string
    {
        $config = (array) config('emp-deliverability.server_health', []);
        $minimum = (int) ($config['min_attempts'] ?? 20);

        if ($attempts < $minimum) {
            return 'ok';
        }

        $critical = (float) ($config['critical'] ?? 0.25);
        $warning = (float) ($config['warning'] ?? 0.1);

        if ($critical > 0 && $rate >= $critical) {
            return 'critical';
        }

        return $warning > 0 && $rate >= $warning ? 'warning' : 'ok';
    }

    /**
     * Latest driver error per server.
     *
     * @return array<int, string>
     */
    private function lastErrors(Carbon $since): array
    {
        $logs = CampaignDeliveryLog::query()
            ->whereNotNull('delivery_server_id')
            ->where('status', CampaignDeliveryLog::STATUS_FAILED)
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(500)
            ->get(['delivery_server_id', 'response']);

        $errors = [];

        foreach ($logs as $log) {
            $id = (int) $log->delivery_server_id;

            if (! isset($errors[$id]) && $log->response !== null && $log->response !== '') {
                $errors[$id] = mb_substr((string) $log->response, 0, 240);
            }
        }

        return $errors;
    }
}
