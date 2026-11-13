<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CountryResource\Pages;

use App\Filament\Admin\Resources\CountryResource;
use App\Services\Geo\GeoDataImporter;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * §3.23 F-3.23-004: countries list + the two import paths — the built-in ISO
 * 3166-1 dataset (same list App\Support\Geo\Countries falls back to) and an
 * operator CSV/JSON upload.
 */
class ListCountries extends ListRecords
{
    protected static string $resource = CountryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            Actions\Action::make('importBuiltIn')
                ->label(__('ops.countries.actions.import_builtin'))
                ->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()
                ->modalDescription(__('ops.countries.actions.import_builtin_help'))
                ->action(function (): void {
                    $count = app(GeoDataImporter::class)->importBuiltInCountries();

                    Notification::make()
                        ->success()
                        ->title(__('ops.geo.imported_countries', ['count' => $count]))
                        ->send();
                }),

            Actions\Action::make('importFile')
                ->label(__('ops.countries.actions.import_file'))
                ->icon('heroicon-o-document-arrow-up')
                ->form([
                    FileUpload::make('file')
                        ->label(__('ops.countries.actions.import_file_label'))
                        ->helperText(__('ops.countries.actions.import_file_help'))
                        ->required()
                        ->disk('local')
                        ->directory('imports/geo')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/json', 'application/csv'])
                        ->maxSize(8192),
                ])
                ->action(function (array $data): void {
                    self::importUpload($data, 'countries');
                }),
        ];
    }

    /** Shared by the countries + zones import actions. */
    public static function importUpload(array $data, string $type): void
    {
        $path = (string) ($data['file'] ?? '');
        $importer = app(GeoDataImporter::class);

        try {
            $contents = (string) Storage::disk('local')->get($path);
            $rows = $importer->parse($contents, $importer->formatFor($path));

            $count = $type === 'zones'
                ? $importer->importZones($rows)
                : $importer->importCountries($rows);
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title(__('ops.geo.import_failed', ['error' => $exception->getMessage()]))
                ->send();

            return;
        } finally {
            Storage::disk('local')->delete($path);
        }

        Notification::make()
            ->success()
            ->title($type === 'zones'
                ? __('ops.geo.imported_zones', ['count' => $count])
                : __('ops.geo.imported_countries', ['count' => $count]))
            ->send();
    }
}
