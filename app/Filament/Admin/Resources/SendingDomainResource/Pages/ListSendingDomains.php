<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SendingDomainResource\Pages;

use App\Filament\Admin\Resources\SendingDomainResource;
use App\Jobs\Infra\VerifyDomainsJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * Admin sending domains index (spec §3.13.5): global list with the
 * verify-all header action (queued — bulk DNS churn never runs in the UI
 * request).
 */
class ListSendingDomains extends ListRecords
{
    protected static string $resource = SendingDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('verify_all')
                ->label(__('infra.actions.verify_all'))
                ->icon('heroicon-o-shield-check')
                ->requiresConfirmation()
                ->action(function (): void {
                    VerifyDomainsJob::dispatch(VerifyDomainsJob::TYPE_SENDING);

                    Notification::make()->title(__('infra.notifications.verify_all_queued'))->success()->send();
                }),
        ];
    }
}
