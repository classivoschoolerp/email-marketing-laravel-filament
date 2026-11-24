<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LandingPageOverviewResource\Pages;

use App\Filament\Admin\Resources\LandingPageOverviewResource;
use Filament\Resources\Pages\ListRecords;

class ListLandingPageOverviews extends ListRecords
{
    protected static string $resource = LandingPageOverviewResource::class;
}
