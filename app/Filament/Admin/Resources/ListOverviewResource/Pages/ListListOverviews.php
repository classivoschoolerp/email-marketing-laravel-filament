<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ListOverviewResource\Pages;

use App\Filament\Admin\Resources\ListOverviewResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Global lists index (spec §3.6 F-3.6-001, WP-18).
 */
class ListListOverviews extends ListRecords
{
    protected static string $resource = ListOverviewResource::class;
}
