<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\LandingPageResource\Pages;

use App\Filament\Customer\Resources\LandingPageResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create a landing page (§2.6). Quota-gated by LandingPagePolicy::create
 * (`max_landing_pages` + `landing_pages.enabled`, §3.5).
 *
 * A new page lands on the TEMPLATE GALLERY rather than in the builder: the
 * first decision is which design to start from (F-2.6-003), and applying a
 * design replaces the document — offering it after the customer has already
 * edited would mean throwing their work away.
 */
class CreateLandingPage extends CreateRecord
{
    protected static string $resource = LandingPageResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return LandingPageResource::normalize($data);
    }

    protected function getRedirectUrl(): string
    {
        return LandingPageResource::getUrl('gallery', ['record' => $this->getRecord()]);
    }
}
