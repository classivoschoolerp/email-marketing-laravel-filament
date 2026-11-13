<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\ConsentEventResource\Pages;

use App\Filament\Customer\Resources\ConsentEventResource;
use Filament\Resources\Pages\ListRecords;

/** Customer-facing consent audit trail (spec §4.5 F-4.5-008). */
class ListConsentEvents extends ListRecords
{
    protected static string $resource = ConsentEventResource::class;

    public function getSubheading(): ?string
    {
        return __('privacy.consent_events.description');
    }
}
