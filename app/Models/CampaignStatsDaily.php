<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily per-campaign stats rollup row (spec §0.6 F-0.6-008), written by the
 * hourly `stats:rollup` cron and read by WP-17/18 reports. Global model.
 */
class CampaignStatsDaily extends Model
{
    protected $table = 'campaign_stats_daily';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'sent' => 'integer',
            'failed' => 'integer',
            'skipped' => 'integer',
            'opens' => 'integer',
            'unique_opens' => 'integer',
            'clicks' => 'integer',
            'unique_clicks' => 'integer',
            'unsubscribes' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withoutGlobalScopes();
    }
}
