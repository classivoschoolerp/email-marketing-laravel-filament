<?php

declare(strict_types=1);

namespace App\Sending\Pipeline;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignStatusMachine;
use App\Services\Settings\SettingsReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-pause thresholds (§3.25.3 F-3.25.3-010, WP-13): after each delivery-log
 * insert the bounce/complaint rates are checked against the operator limits
 * and the campaign is paused through the status machine when breached.
 *
 * WP-15 seam: bounce and FBL polling land with WP-15 and will populate
 * `campaign_bounce_logs` / `campaign_complaints` (campaign_id + subscriber_id
 * per event). Until those tables exist the rates read as 0 — the gate is
 * wired, dormant, and covered by tests that create the tables.
 * 0% threshold = disabled (D-23).
 */
class CampaignAutoPause
{
    public const BOUNCE_TABLE = 'campaign_bounce_logs';

    public const COMPLAINT_TABLE = 'campaign_complaints';

    public function __construct(private readonly CampaignStatusMachine $machine) {}

    /** @return bool true when the campaign was paused */
    public function evaluate(Campaign $campaign): bool
    {
        if ($campaign->status !== CampaignStatus::Sending) {
            return false;
        }

        $sent = (int) $campaign->delivery_sent;

        if ($sent < 1) {
            return false;
        }

        $bounceLimit = SettingsReader::campaign()->bounceAutoPausePercent();
        $complaintLimit = SettingsReader::campaign()->complaintAutoPausePercent();

        if ($bounceLimit > 0 && $this->ratePercent(self::BOUNCE_TABLE, $campaign, $sent) >= $bounceLimit) {
            return $this->pause($campaign, __('sending.auto_pause.bounce_threshold', ['limit' => $bounceLimit]));
        }

        if ($complaintLimit > 0 && $this->ratePercent(self::COMPLAINT_TABLE, $campaign, $sent) >= $complaintLimit) {
            return $this->pause($campaign, __('sending.auto_pause.complaint_threshold', ['limit' => $complaintLimit]));
        }

        return false;
    }

    private function ratePercent(string $table, Campaign $campaign, int $sent): float
    {
        if (! Schema::hasTable($table)) {
            return 0.0; // WP-15 seam — table not created yet
        }

        $events = (int) DB::table($table)->where('campaign_id', $campaign->id)->count();

        return $events * 100 / $sent;
    }

    private function pause(Campaign $campaign, string $reason): bool
    {
        $this->machine->transition($campaign, CampaignStatus::Paused);

        $campaign->forceFill([
            'notes' => trim((string) $campaign->notes."\n".$reason),
        ])->saveQuietly();

        return true;
    }
}
