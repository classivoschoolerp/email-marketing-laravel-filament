<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BounceRuleResource\Pages;

use App\Filament\Admin\Resources\BounceRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBounceRule extends EditRecord
{
    protected static string $resource = BounceRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
