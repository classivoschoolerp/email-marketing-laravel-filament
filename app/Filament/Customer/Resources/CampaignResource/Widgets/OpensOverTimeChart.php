<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Widgets;

/** Opens-over-time chart (spec §2.3.8 F-2.3.8-011). */
class OpensOverTimeChart extends EngagementOverTimeChart
{
    protected function metric(): string
    {
        return 'opens';
    }

    public function getHeading(): string
    {
        return __('reports.charts.opens_over_time');
    }
}
