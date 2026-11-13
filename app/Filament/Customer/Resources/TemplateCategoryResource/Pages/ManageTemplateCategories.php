<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\TemplateCategoryResource\Pages;

use App\Filament\Customer\Resources\TemplateCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTemplateCategories extends ManageRecords
{
    protected static string $resource = TemplateCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('templates.categories.create')),
        ];
    }
}
