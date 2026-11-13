<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CampaignComplaintLogResource\Pages;

use App\Filament\Admin\Resources\CampaignComplaintLogResource;
use Filament\Resources\Pages\ListRecords;

/** Read-only index — see CampaignComplaintLogResource (spec §3.27 F-3.27-003). */
class ListCampaignComplaintLogs extends ListRecords
{
    protected static string $resource = CampaignComplaintLogResource::class;
}
