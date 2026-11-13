<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\DeliveryServerResource\Pages;

use App\Enums\DeliveryServerStatus;
use App\Filament\Customer\Resources\DeliveryServerResource;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Sending\ServerValidator;
use App\Services\Quota\QuotaService;
use App\Services\Sending\DeliveryServerAssignments;
use App\Services\Sending\DeliveryServerQuota;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\Action;

/**
 * Delivery servers index (spec §2.9.1 basics — the full admin index is
 * WP-15). Custom row actions: validate (F-2.9.2-041), enable/disable, copy.
 */
class ListDeliveryServers extends ListRecords
{
    protected static string $resource = DeliveryServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(), // hidden when DeliveryServerPolicy::create denies
        ];
    }

    private static function currentCustomer(): ?Customer
    {
        $user = auth('customer')->user();

        return $user instanceof Customer ? $user : null;
    }

    /** F-2.9.2-041 — send the confirmation email through the actual driver. */
    public static function validateAction(): Action
    {
        return Action::make('validate')
            ->label(__('sending.actions.validate'))
            ->icon('heroicon-o-check-badge')
            ->requiresConfirmation()
            ->modalDescription(__('sending.actions.validate_description'))
            ->visible(fn (DeliveryServer $record): bool => ($customer = self::currentCustomer()) !== null
                && $record->isEditableByCustomer($customer))
            ->action(function (DeliveryServer $record): void {
                $customer = self::currentCustomer();

                abort_unless($customer !== null && $record->isEditableByCustomer($customer), 403);

                $result = app(ServerValidator::class)->begin($record);

                if ($result->ok) {
                    Notification::make()
                        ->title(__('sending.notifications.validation_sent'))
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title(__('sending.notifications.validation_failed'))
                        ->body((string) $result->error)
                        ->danger()
                        ->send();
                }
            });
    }

    /** Enable/disable toggle (spec §2.9.1 actions). */
    public static function toggleAction(): Action
    {
        return Action::make('toggle')
            ->label(fn (DeliveryServer $record): string => $record->status === DeliveryServerStatus::Active || $record->status === DeliveryServerStatus::HourlyQuotaReached
                ? __('sending.actions.disable')
                : __('sending.actions.enable'))
            ->icon(fn (DeliveryServer $record): string => $record->isActive() ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
            ->visible(fn (DeliveryServer $record): bool => ($customer = self::currentCustomer()) !== null
                && $record->isEditableByCustomer($customer))
            ->action(function (DeliveryServer $record): void {
                $customer = self::currentCustomer();

                abort_unless($customer !== null && $record->isEditableByCustomer($customer), 403);

                if ($record->status === DeliveryServerStatus::Active
                    || $record->status === DeliveryServerStatus::HourlyQuotaReached) {
                    $record->forceFill(['status' => DeliveryServerStatus::Disabled])->save();

                    Notification::make()->title(__('sending.notifications.disabled'))->success()->send();

                    return;
                }

                // Enabling: the group matrix may demand validation first
                // (servers.must_validate + F-2.9.2-041).
                $mustValidate = app(QuotaService::class)->allows($customer, 'servers.must_validate');

                if ($mustValidate && ! $record->isValidated()) {
                    Notification::make()
                        ->title(__('sending.notifications.must_validate'))
                        ->warning()
                        ->send();

                    return;
                }

                $record->forceFill(['status' => DeliveryServerStatus::Active])->save();

                Notification::make()->title(__('sending.notifications.enabled'))->success()->send();
            });
    }

    /** Copy (spec §2.9.1 actions) — quota-checked like any new server. */
    public static function copyAction(): Action
    {
        return Action::make('copy')
            ->label(__('sending.actions.copy'))
            ->icon('heroicon-o-document-duplicate')
            ->visible(fn (DeliveryServer $record): bool => ($customer = self::currentCustomer()) !== null
                && $record->isEditableByCustomer($customer))
            ->action(function (DeliveryServer $record): void {
                $customer = self::currentCustomer();

                abort_unless($customer !== null && $record->isEditableByCustomer($customer), 403);

                // A copy is a new server: full create-gate chain applies.
                app(DeliveryServerQuota::class)->assertCanCreate($customer, $record->type);

                $copy = $record->replicate(['uid', 'validation_token', 'validated_at', 'last_used_at']);
                $copy->uid = null; // regenerated by the creating hook
                $copy->name = $record->name.' '.__('sending.labels.copy_suffix');
                $copy->status = DeliveryServerStatus::Inactive;
                $copy->save();

                // F-2.9.2-038 (WP-42): carry the list/campaign restriction over.
                // `replicate()` copies columns, not related rows, so without
                // this a copy of a server fenced to two lists would come out
                // able to send anything — a restriction must never widen by
                // accident. The rows are re-validated against the tenant on
                // the way in, exactly like a form save.
                $assignments = app(DeliveryServerAssignments::class);
                $restriction = $assignments->forServer($record);

                if ($restriction['list'] !== [] || $restriction['campaign'] !== []) {
                    $assignments->sync($copy, $restriction['list'], $restriction['campaign']);
                }

                Notification::make()->title(__('sending.notifications.copied'))->success()->send();
            });
    }
}
