<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\RequiresServerPermission;
use App\Services\Sending\ServerHealthReport;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Server health / error-rate dashboard (spec §3.13.1 F-3.13.1-007, WP-33):
 * volume, failures and error rate per delivery server over the configured
 * window, worst first, with the last real driver error next to each row.
 */
class ServerHealthPage extends Page
{
    use RequiresServerPermission;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'System Health';

    protected static string $view = 'filament.admin.pages.server-health';

    protected static ?string $slug = 'server-health';

    protected static ?int $navigationSort = 75;

    public static function getNavigationGroup(): ?string
    {
        return __('infra.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('deliverability.health.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('deliverability.health.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('deliverability.health.subheading', ['days' => $this->windowDays()]);
    }

    public function windowDays(): int
    {
        return max(1, (int) config('emp-deliverability.server_health.window_days', 7));
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $report = app(ServerHealthReport::class);
        $rows = $report->rows($this->windowDays());

        return [
            'rows' => $rows,
            'totals' => $report->totals($rows),
            'days' => $this->windowDays(),
        ];
    }
}
