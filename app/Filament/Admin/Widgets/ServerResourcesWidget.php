<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\SystemInfoPage;
use App\Services\Reports\SystemInfoService;
use Filament\Widgets\Widget;

/**
 * §3.1 F-3.1-006 — the server-resources widget on the admin dashboard
 * (WP-40): disk free/total, PHP memory limit and current usage, and the
 * database size.
 *
 * Reads {@see SystemInfoService}, the WP-17 owner of these figures, so the
 * dashboard card and the §3.27 system-info page can never disagree. A metric
 * the host refuses to report renders as "—" with no number invented — on a
 * restricted shared host `disk_free_space()` returning false is a real
 * outcome, not zero bytes free.
 *
 * Gated by the same permission as the system-info page it summarises.
 */
class ServerResourcesWidget extends Widget
{
    protected static string $view = 'filament.admin.widgets.server-resources';

    protected static bool $isLazy = false;

    protected static ?int $sort = 46;

    protected int|string|array $columnSpan = 'full';

    /** Disk usage at or above this share is flagged. */
    public const DISK_WARNING = 0.80;

    public const DISK_CRITICAL = 0.90;

    public static function canView(): bool
    {
        return SystemInfoPage::canAccess();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $info = app(SystemInfoService::class);

        $disk = $info->diskSpace();
        $used = $disk['total'] === null || $disk['free'] === null
            ? null
            : $disk['total'] - $disk['free'];

        $share = $disk['total'] !== null && $disk['total'] > 0 && $used !== null
            ? $used / $disk['total']
            : null;

        return [
            'info' => $info,
            'disk' => $disk,
            'diskUsed' => $used,
            'diskPercent' => $share === null ? null : (int) round($share * 100),
            'diskStatus' => match (true) {
                $share === null => 'unknown',
                $share >= self::DISK_CRITICAL => 'critical',
                $share >= self::DISK_WARNING => 'warning',
                default => 'ok',
            },
            'memoryLimit' => (string) ($info->limits()['memory_limit'] ?? '—'),
            'memoryUsage' => memory_get_usage(true),
            'memoryPeak' => memory_get_peak_usage(true),
            'databaseBytes' => $info->databaseSizeBytes(),
            'storage' => $info->storageUsage(),
            'systemInfoUrl' => SystemInfoPage::getUrl(panel: 'admin'),
        ];
    }
}
