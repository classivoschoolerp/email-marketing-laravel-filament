<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCronJob;
use App\Enums\DeliveryServerStatus;
use App\Models\CronRun;
use App\Models\DeliveryServer;
use App\Sending\ServerPicker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `quotas:reset` (spec §0.6 F-0.6-015, hourly). The hourly/daily/monthly
 * counters are window-keyed by design (HourlyWindowLimiter cache windows +
 * delivery_server_usage day rows) so they "reset" implicitly — this job does
 * the housekeeping the windows cannot: proactively flips
 * hourly-quota-reached servers back to active once their window rolled
 * (F-2.9.2-042 — otherwise they recover only lazily on the next pick) and
 * prunes daily usage rollups older than 13 months (kept that long so the
 * monthly quota window stays computable).
 */
class QuotasResetCommand extends Command
{
    use RunsAsCronJob;

    public const JOB_NAME = 'quotas:reset';

    protected $signature = 'quotas:reset';

    protected $description = 'Refresh delivery-server quota statuses and prune stale usage rollups.';

    protected function process(CronRun $run): int
    {
        $picker = app(ServerPicker::class);
        $refreshed = 0;

        $servers = DeliveryServer::withoutTenancy()
            ->whereIn('status', [
                DeliveryServerStatus::Active->value,
                DeliveryServerStatus::HourlyQuotaReached->value,
            ])
            ->get();

        foreach ($servers as $server) {
            $before = $server->status;
            $picker->refreshQuotaStatus($server);

            if ($server->status !== $before) {
                $refreshed++;
            }
        }

        DB::table('delivery_server_usage')
            ->where('date', '<', Carbon::now()->subMonths(13)->toDateString())
            ->delete();

        return $refreshed;
    }
}
