<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Sending\RefreshSendTimeProfilesJob;
use App\Models\Customer;
use Illuminate\Console\Command;

/**
 * send-time:profile (spec §2.3.4 F-2.3.4-011, WP-46).
 *
 * DISPATCHES the recomputation, it does not perform it: the command returns
 * immediately and RefreshSendTimeProfilesJob does the work on the maintenance
 * queue, one job per tenant.
 *
 * This is NOT one of the D-40 §0.6 canonical cron jobs — `CronSettings::
 * KNOWN_JOBS` is a fixed list of 17 names and adding an eighteenth would
 * change a frozen settings contract. Operators schedule it themselves (daily
 * is plenty: SendTimeProfiler::STALE_HOURS is a week); the campaign editor
 * also offers a "recompute" button that dispatches the same job, and
 * StartCampaignSendJob dispatches it when an optimized campaign starts with
 * stale profiles.
 */
class SendTimeProfileCommand extends Command
{
    protected $signature = 'send-time:profile
        {--customer= : Only this customer id}
        {--list= : Only this list id (requires --customer)}';

    protected $description = 'Queue a rebuild of the per-subscriber send-time profiles (F-2.3.4-011)';

    public function handle(): int
    {
        $customerId = $this->option('customer') !== null ? (int) $this->option('customer') : null;
        $listId = $this->option('list') !== null ? (int) $this->option('list') : null;

        if ($listId !== null && $customerId === null) {
            $this->error(__('sending.send_time.list_needs_customer'));

            return self::FAILURE;
        }

        $ids = $customerId !== null
            ? [$customerId]
            : Customer::query()->withoutGlobalScopes()->orderBy('id')->pluck('id')->map(intval(...))->all();

        foreach ($ids as $id) {
            RefreshSendTimeProfilesJob::dispatch($id, $listId);
        }

        $this->info(__('sending.send_time.queued', ['count' => count($ids)]));

        return self::SUCCESS;
    }
}
