<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TrackingDomainResource\Pages;

use App\Filament\Admin\Resources\TrackingDomainResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTrackingDomain extends CreateRecord
{
    protected static string $resource = TrackingDomainResource::class;
}
