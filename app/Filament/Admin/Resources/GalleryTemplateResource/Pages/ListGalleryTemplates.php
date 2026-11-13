<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GalleryTemplateResource\Pages;

use App\Filament\Admin\Resources\GalleryTemplateResource;
use App\Jobs\Templates\ImportGalleryPackJob;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListGalleryTemplates extends ListRecords
{
    protected static string $resource = GalleryTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import_pack')
                ->label(__('templates.actions.import_pack'))
                ->icon('heroicon-m-arrow-up-tray')
                ->modalDescription(__('templates.help.pack_format'))
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label(__('templates.fields.zip_file'))
                        ->disk('local')
                        ->directory('tmp/gallery-packs')
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    // Pack size is unbounded → always queued (docs/ARCHITECTURE.md rule 5).
                    ImportGalleryPackJob::dispatch((string) $data['file']);

                    Notification::make()->success()
                        ->title(__('templates.notifications.pack_queued'))
                        ->send();
                }),

            Actions\CreateAction::make()
                ->label(__('templates.actions.create')),
        ];
    }
}
