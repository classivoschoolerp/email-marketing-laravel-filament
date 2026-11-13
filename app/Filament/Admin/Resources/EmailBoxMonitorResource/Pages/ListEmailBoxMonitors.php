<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailBoxMonitorResource\Pages;

use App\Filament\Admin\Resources\EmailBoxMonitorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmailBoxMonitors extends ListRecords
{
    protected static string $resource = EmailBoxMonitorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
