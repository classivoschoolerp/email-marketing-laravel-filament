<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CampaignDeliveryLogResource\Pages;

use App\Filament\Admin\Resources\CampaignDeliveryLogResource;
use Filament\Resources\Pages\ListRecords;

/** Read-only index — see CampaignDeliveryLogResource (spec §3.27 F-3.27-001). */
class ListCampaignDeliveryLogs extends ListRecords
{
    protected static string $resource = CampaignDeliveryLogResource::class;
}
