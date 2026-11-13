<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GalleryTemplateResource\Pages;

use App\Filament\Admin\Resources\GalleryTemplateResource;
use App\Models\EmailTemplate;
use App\Services\Templates\TemplateThumbnails;
use App\Services\Templates\UnsubscribeTagChecker;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGalleryTemplate extends EditRecord
{
    protected static string $resource = GalleryTemplateResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['thumbnail_uploaded'] = filled($data['thumbnail_path'] ?? null);
        $data['missing_unsubscribe_tag'] = ! app(UnsubscribeTagChecker::class)
            ->passes($data['content_html'] ?? null);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var EmailTemplate $record */
        $record = $this->getRecord();

        app(TemplateThumbnails::class)->refreshFromContent($record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
