<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\ErrorLogPage;
use App\Filament\Admin\Pages\OpsDashboardPage;
use App\Services\Ops\ErrorLogWatcher;
use Filament\Widgets\Widget;

/**
 * §6.6 F-6.6-002 — recent system errors surfaced inside the admin area.
 * Polls the log tail (WP-17 LogFileReader) every 30s; the accompanying
 * `ops:monitor` sweep turns new entries into Filament database
 * notifications so an operator is told even without this page open.
 */
class SystemErrorsWidget extends Widget
{
    protected static string $view = 'filament.admin.widgets.system-errors';

    protected static bool $isLazy = false;

    protected static ?int $sort = 46;

    public static function canView(): bool
    {
        return OpsDashboardPage::canAccess();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $watcher = app(ErrorLogWatcher::class);

        return [
            'entries' => $watcher->recent(5),
            'newCount' => count($watcher->newEntries()),
            'minimumLevel' => $watcher->minimumLevel(),
            'enabled' => $watcher->enabled(),
            'logUrl' => ErrorLogPage::getUrl(panel: 'admin'),
        ];
    }
}
