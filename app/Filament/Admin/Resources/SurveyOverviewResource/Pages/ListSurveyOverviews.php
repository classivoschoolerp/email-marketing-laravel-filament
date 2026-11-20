<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SurveyOverviewResource\Pages;

use App\Filament\Admin\Resources\SurveyOverviewResource;
use Filament\Resources\Pages\ListRecords;

class ListSurveyOverviews extends ListRecords
{
    protected static string $resource = SurveyOverviewResource::class;
}
