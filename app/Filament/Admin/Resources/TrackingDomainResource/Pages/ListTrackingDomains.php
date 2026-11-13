<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TrackingDomainResource\Pages;

use App\Filament\Admin\Resources\TrackingDomainResource;
use App\Jobs\Infra\VerifyDomainsJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * Admin tracking domains index (spec §3.13.6): global list with the queued
 * verify-all header action.
 */
class ListTrackingDomains extends ListRecords
{
    protected static string $resource = TrackingDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('verify_all')
                ->label(__('infra.actions.verify_all'))
                ->icon('heroicon-o-shield-check')
                ->requiresConfirmation()
                ->action(function (): void {
                    VerifyDomainsJob::dispatch(VerifyDomainsJob::TYPE_TRACKING);

                    Notification::make()->title(__('infra.notifications.verify_all_queued'))->success()->send();
                }),
        ];
    }
}
