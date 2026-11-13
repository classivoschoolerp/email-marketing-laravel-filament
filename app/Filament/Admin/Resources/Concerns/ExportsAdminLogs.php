<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Concerns;

use App\Jobs\Reports\RunAdminLogExportJob;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Support\Str;

/**
 * Queued CSV export header action for the §3.27 admin log resources
 * (F-3.27-015 "export logs", WP-17). The runner emails the requesting admin
 * a signed, expiring download link.
 */
trait ExportsAdminLogs
{
    protected static function logExportAction(string $type): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('export_log')
            ->label(__('reports.actions.export_csv'))
            ->icon('heroicon-m-arrow-down-tray')
            ->action(function () use ($type): void {
                RunAdminLogExportJob::dispatch($type, (int) auth('admin')->id(), Str::random(32));

                Notification::make()->success()
                    ->title(__('reports.notifications.admin_export_queued'))
                    ->send();
            });
    }
}
