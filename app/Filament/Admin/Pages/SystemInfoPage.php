<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\RequiresLogsPermission;
use App\Services\Reports\SystemInfoService;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * PHP / system info (spec §3.27 F-3.27-014, WP-17): versions, extensions,
 * ini limits, disk space, driver-aware database size and storage usage —
 * plus the read-only log retention section (F-3.27-015: values come from
 * config/emp-sending.php `retention`, enforced nightly by logs:prune;
 * WP-22's settings group supersedes as the operator-facing editor).
 */
class SystemInfoPage extends Page
{
    use RequiresLogsPermission;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'System Health';

    protected static string $view = 'filament.admin.pages.system-info';

    protected static ?string $slug = 'logs/system-info';

    protected static ?int $navigationSort = 118;

    public static function getNavigationGroup(): ?string
    {
        return __('reports.admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.admin.system_info.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('reports.admin.system_info.title');
    }

    public function info(): SystemInfoService
    {
        return app(SystemInfoService::class);
    }
}
