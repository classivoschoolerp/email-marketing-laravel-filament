<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LoginLogResource\Pages;

use App\Enums\LoginResult;
use App\Filament\Admin\Resources\LoginLogResource;
use App\Filament\Admin\Resources\LoginLogResource\Widgets\CurrentLockoutsWidget;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Login logs index (spec §3.27 F-3.27-005/006): all / guest failed attempts
 * / successes tabs + the live brute-force lockout widget.
 */
class ListLoginLogs extends ListRecords
{
    protected static string $resource = LoginLogResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('reports.admin.logins.tabs.all')),
            // Guest failed attempts (F-3.27-006): every non-success outcome.
            'failed' => Tab::make(__('reports.admin.logins.tabs.failed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('result', '!=', LoginResult::Success->value)),
            'success' => Tab::make(__('reports.admin.logins.tabs.success'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('result', LoginResult::Success->value)),
        ];
    }

    /** @return array<int, mixed> */
    protected function getHeaderWidgets(): array
    {
        return [
            CurrentLockoutsWidget::class,
        ];
    }
}
