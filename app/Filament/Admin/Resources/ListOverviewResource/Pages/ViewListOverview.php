<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ListOverviewResource\Pages;

use App\Filament\Admin\Resources\ListOverviewResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only list drill for admins (spec §3.6 F-3.6-002, WP-18): details +
 * per-status stats (infolist) and the subscribers relation table.
 */
class ViewListOverview extends ViewRecord
{
    protected static string $resource = ListOverviewResource::class;
}
