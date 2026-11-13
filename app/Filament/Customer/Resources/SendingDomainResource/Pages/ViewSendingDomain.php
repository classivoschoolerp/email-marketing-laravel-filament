<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SendingDomainResource\Pages;

use App\Filament\Customer\Resources\SendingDomainResource;
use App\Models\SendingDomain;
use App\Services\Domains\SendingDomainVerifier;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * DNS record sheet + one-click verify (spec §2.9.7 F-2.9.7-002/003/005) and
 * the BIMI record sheet (F-2.9.7-009, WP-46).
 */
class ViewSendingDomain extends ViewRecord
{
    protected static string $resource = SendingDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('verify')
                ->label(__('infra.actions.verify'))
                ->icon('heroicon-o-shield-check')
                ->action(function (): void {
                    /** @var SendingDomain $record */
                    $record = $this->getRecord();
                    $record = app(SendingDomainVerifier::class)->verify($record);

                    // F-2.9.7-009: the BIMI record is resolved on the same
                    // click. Asset fetching stays in the queued CheckBimiJob.
                    SendingDomainResource::refreshBimiForDomain($record);

                    $record->isVerified()
                        ? Notification::make()->title(__('infra.notifications.domain_verified'))->success()->send()
                        : Notification::make()->title(__('infra.notifications.domain_failed'))->danger()->send();

                    $this->refreshFormData([
                        'status',
                        'dkim_verified',
                        'spf_verified',
                        'dmarc_verified',
                        'mx_verified',
                        'bimi_verified',
                        'bimi_checked_at',
                        'bimi_findings',
                        'last_checked_at',
                    ]);
                }),
            SendingDomainResource::checkBimiAction(Actions\Action::class),
            Actions\EditAction::make(),
        ];
    }
}
