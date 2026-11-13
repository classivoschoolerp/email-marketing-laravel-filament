<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GalleryTemplateResource\Pages;

use App\Filament\Admin\Resources\GalleryTemplateResource;
use App\Models\EmailTemplate;
use App\Services\Templates\TemplateThumbnails;
use App\Services\Templates\UnsubscribeTagChecker;
use Filament\Resources\Pages\CreateRecord;

class CreateGalleryTemplate extends CreateRecord
{
    protected static string $resource = GalleryTemplateResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['customer_id'] = null; // system row (spec §3.14)
        $data['thumbnail_uploaded'] = filled($data['thumbnail_path'] ?? null);
        $data['missing_unsubscribe_tag'] = ! app(UnsubscribeTagChecker::class)
            ->passes($data['content_html'] ?? null);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var EmailTemplate $record */
        $record = $this->getRecord();

        app(TemplateThumbnails::class)->refreshFromContent($record);
    }
}
