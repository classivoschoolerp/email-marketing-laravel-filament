<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailTemplateResource\Pages;

use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Services\Templates\Builders\BuilderRegistry;
use App\Services\Templates\TemplateQuota;
use App\Services\Templates\TemplateRevisions;
use Filament\Resources\Pages\CreateRecord;

/**
 * New template (spec §2.5.3 — the builder is chosen per template; the select
 * defaults to the customer's preference, then the operator default). After
 * creation the customer lands directly in the chosen editor.
 */
class CreateEmailTemplate extends CreateRecord
{
    protected static string $resource = EmailTemplateResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        app(TemplateQuota::class)->assertCanCreate(auth('customer')->user());

        $data['content_html'] = EmailTemplateResource::starterHtml();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var EmailTemplate $record */
        $record = $this->getRecord();

        app(TemplateRevisions::class)->record($record);
    }

    protected function getRedirectUrl(): string
    {
        /** @var EmailTemplate $record */
        $record = $this->getRecord();

        return app(BuilderRegistry::class)
            ->get(EmailTemplateResource::builderCase($record))
            ->editUrl($record);
    }
}
