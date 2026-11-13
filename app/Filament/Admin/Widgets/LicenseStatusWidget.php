<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Services\Settings\SettingsReader;
use Filament\Widgets\Widget;

/**
 * License status widget (spec §3.1 F-3.1-008, WP-18): current status, type,
 * expiry and last check from the §3.25.12 license settings group (D-09).
 */
class LicenseStatusWidget extends Widget
{
    protected static string $view = 'filament.admin.widgets.license-status';

    protected static bool $isLazy = false;

    protected static ?int $sort = 60;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $license = SettingsReader::license();

        return [
            'status' => $license->status(),
            'valid' => $license->valid(),
            'type' => $license->type(),
            'expiresAt' => $license->expiresAt(),
            'lastCheckedAt' => $license->lastCheckedAt(),
            'renewalUrl' => $license->renewalUrl(),
        ];
    }
}
