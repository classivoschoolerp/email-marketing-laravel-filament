<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\FormResource\Pages;

use App\Filament\Customer\Resources\FormResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create a form (§2.7). Quota-gated by FormPolicy::create (`max_forms` +
 * `forms.enabled`, §3.5). The three JSON rule documents are normalised on
 * the way in so the embed loader only ever sees a complete schema.
 */
class CreateForm extends CreateRecord
{
    protected static string $resource = FormResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return FormResource::normalizeJsonDocuments($data);
    }

    protected function getRedirectUrl(): string
    {
        // Straight into the field builder — a form without inputs is useless.
        return FormResource::getUrl('fields', ['record' => $this->getRecord()]);
    }
}
