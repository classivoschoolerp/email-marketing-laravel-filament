<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ConsentEventResource\Pages;

use App\Filament\Admin\Resources\ConsentEventResource;
use Filament\Resources\Pages\ListRecords;

/** Install-wide consent audit trail (spec §4.5 F-4.5-008). */
class ListConsentEvents extends ListRecords
{
    protected static string $resource = ConsentEventResource::class;

    public function getSubheading(): ?string
    {
        return __('privacy.consent_events.description');
    }
}
