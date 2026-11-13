<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ErasureRequestResource\Pages;

use App\Filament\Admin\Resources\ErasureRequestResource;
use App\Services\Settings\SettingsReader;
use Filament\Resources\Pages\ListRecords;

/**
 * The §3.25.16 erasure queue. The subheading surfaces the workflow toggle
 * state so an administrator sees at a glance whether the public form is
 * reachable (the §3.19 block-request precedent).
 */
class ListErasureRequests extends ListRecords
{
    protected static string $resource = ErasureRequestResource::class;

    public function getSubheading(): ?string
    {
        return SettingsReader::privacy()->erasureWorkflowEnabled()
            ? __('privacy.erasure.admin.workflow_on')
            : __('privacy.erasure.admin.workflow_off');
    }
}
