<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailBoxMonitorResource\Pages;

use App\Filament\Admin\Resources\EmailBoxMonitorResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmailBoxMonitor extends EditRecord
{
    protected static string $resource = EmailBoxMonitorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
