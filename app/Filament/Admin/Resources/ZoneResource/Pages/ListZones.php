<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ZoneResource\Pages;

use App\Filament\Admin\Resources\CountryResource\Pages\ListCountries;
use App\Filament\Admin\Resources\ZoneResource;
use App\Services\Geo\GeoDataImporter;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * §3.23 F-3.23-005: zones list + the built-in US/CA/AU/IN seed import and an
 * operator CSV/JSON upload (`country_code,code,name,type`).
 */
class ListZones extends ListRecords
{
    protected static string $resource = ZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            Actions\Action::make('importBuiltIn')
                ->label(__('ops.zones.actions.import_builtin'))
                ->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()
                ->modalDescription(__('ops.zones.actions.import_builtin_help'))
                ->action(function (): void {
                    $importer = app(GeoDataImporter::class);

                    // Zones need their parent countries — import both.
                    $importer->importBuiltInCountries();
                    $count = $importer->importBuiltInZones();

                    Notification::make()
                        ->success()
                        ->title(__('ops.geo.imported_zones', ['count' => $count]))
                        ->send();
                }),

            Actions\Action::make('importFile')
                ->label(__('ops.zones.actions.import_file'))
                ->icon('heroicon-o-document-arrow-up')
                ->form([
                    FileUpload::make('file')
                        ->label(__('ops.zones.actions.import_file_label'))
                        ->helperText(__('ops.zones.actions.import_file_help'))
                        ->required()
                        ->disk('local')
                        ->directory('imports/geo')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/json', 'application/csv'])
                        ->maxSize(8192),
                ])
                ->action(function (array $data): void {
                    ListCountries::importUpload($data, 'zones');
                }),
        ];
    }
}
