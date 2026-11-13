<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SubscriberOverviewResource\Pages;

use App\Filament\Admin\Resources\SubscriberOverviewResource;
use Filament\Resources\Pages\ListRecords;

/**
 * §3.8 global subscriber index (F-3.8-001). Search is the table's own email
 * column search, which runs cross-tenant because the resource drops the
 * D-05 scope.
 */
class ListSubscriberOverview extends ListRecords
{
    protected static string $resource = SubscriberOverviewResource::class;
}
