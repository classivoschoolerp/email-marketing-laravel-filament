<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Widgets;

/** Clicks-over-time chart (spec §2.3.8 F-2.3.8-012). */
class ClicksOverTimeChart extends EngagementOverTimeChart
{
    protected function metric(): string
    {
        return 'clicks';
    }

    public function getHeading(): string
    {
        return __('reports.charts.clicks_over_time');
    }
}
