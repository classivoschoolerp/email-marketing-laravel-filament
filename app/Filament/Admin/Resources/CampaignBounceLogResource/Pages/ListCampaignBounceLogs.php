<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CampaignBounceLogResource\Pages;

use App\Filament\Admin\Resources\CampaignBounceLogResource;
use Filament\Resources\Pages\ListRecords;

/** Read-only index — see CampaignBounceLogResource (spec §3.27 F-3.27-002). */
class ListCampaignBounceLogs extends ListRecords
{
    protected static string $resource = CampaignBounceLogResource::class;
}
