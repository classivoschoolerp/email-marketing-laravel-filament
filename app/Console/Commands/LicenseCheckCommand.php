<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCronJob;
use App\Models\CronRun;
use App\Services\License\LicenseService;
use App\Settings\LicenseSettings;
use Illuminate\Console\Command;

/**
 * `license:check` (spec §0.6 F-0.6-016, daily): re-validates the stored
 * license key against the licensing endpoint and persists the result
 * (LicenseService::validateAndStore — an unreachable endpoint preserves the
 * last known state; no configured endpoint = self-hosted, always valid).
 */
class LicenseCheckCommand extends Command
{
    use RunsAsCronJob;

    public const JOB_NAME = 'license:check';

    protected $signature = 'license:check';

    protected $description = 'Re-validate the platform license and store the result.';

    protected function process(CronRun $run): int
    {
        $result = app(LicenseService::class)->validateAndStore(app(LicenseSettings::class));

        $this->line(__('sending.cron.license_status', ['status' => $result->status]));

        return 1;
    }
}
