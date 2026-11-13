<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AnnouncementResource\Pages;

use App\Filament\Admin\Resources\AnnouncementResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

/**
 * Single-page announcements CRUD (WP-18): create/edit in modals.
 */
class ManageAnnouncements extends ManageRecords
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('dashboards.announcements.actions.create')),
        ];
    }
}
